<?php

namespace Tests\Feature;

use App\Models\DanaSandboxTestRun;
use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\DanaSandboxKeyMaterialFixture;
use Tests\TestCase;

class DanaSandboxTesterApiTest extends TestCase
{
    use RefreshDatabase;

    private const string ServiceToken = 'test-dana-sandbox-tester-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.udev_internal', [
            'enabled' => true,
            'service_id' => 'udev-billing',
            'token' => self::ServiceToken,
            'abilities' => ['merchants:credentials:read', 'merchants:credentials:write'],
        ]);
        config()->set('services.dana.base_url', 'https://dana-sandbox.test');
        config()->set('app.url', 'https://gateway.test');
        $this->app['url']->forceScheme('https');
    }

    public function test_validation_requires_internal_authorization_and_does_not_expose_keys(): void
    {
        $merchant = $this->managedMerchant();
        $config = $this->validConfiguration($merchant);

        $this->getJson($this->validationUrl($merchant))->assertUnauthorized();

        $this->withToken(self::ServiceToken)
            ->getJson($this->validationUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.format_valid', true)
            ->assertJsonPath('data.private_key.configured', true)
            ->assertJsonPath('data.private_key.format_valid', true)
            ->assertJsonPath('data.dana_webhook_public_key.format_valid', true)
            ->assertJsonMissingPath('data.private_key.value')
            ->assertJsonMissingPath('data.dana_webhook_public_key.value')
            ->assertDontSee(DanaSandboxKeyMaterialFixture::PrivateKey)
            ->assertDontSee(DanaSandboxKeyMaterialFixture::PublicKey);

        $run = DanaSandboxTestRun::query()->sole();
        $details = json_encode($run->safe_details, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(DanaSandboxKeyMaterialFixture::PrivateKey, $details);
        $this->assertStringNotContainsString(DanaSandboxKeyMaterialFixture::PublicKey, $details);
        $this->assertNotSame(DanaSandboxKeyMaterialFixture::PrivateKey, $config->getRawOriginal('encrypted_credentials'));

        config()->set('services.udev_internal.abilities', ['merchants:credentials:write']);
        $this->withToken(self::ServiceToken)
            ->getJson($this->validationUrl($merchant))
            ->assertForbidden();
    }

    public function test_validation_reports_invalid_key_format_without_leaking_key_material(): void
    {
        $merchant = $this->managedMerchant();
        $invalidKey = 'private-key-yang-tidak-valid';
        MerchantPaymentConfig::factory()->for($merchant)->create([
            'partner_id' => 'partner-sandbox-001',
            'callback_url' => 'https://merchant.test/dana/return',
            'enabled' => true,
            'encrypted_credentials' => [
                ...$this->credentials(),
                'private_key' => $invalidKey,
            ],
        ]);

        $this->withToken(self::ServiceToken)
            ->getJson($this->validationUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.private_key.configured', true)
            ->assertJsonPath('data.private_key.format_valid', false)
            ->assertJsonPath('data.format_valid', false)
            ->assertDontSee($invalidKey);

        $this->assertDatabaseHas('dana_sandbox_test_runs', [
            'merchant_id' => $merchant->id,
            'operation' => 'configuration_validated',
            'status' => 'failed',
        ]);
    }

    public function test_missing_timestamp_uat_uses_sandbox_credentials_without_creating_a_payment(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        Http::preventStrayRequests();
        Http::fake([
            'https://dana-sandbox.test/payment-gateway/v1.0/debit/payment-host-to-host.htm' => Http::response([
                'responseCode' => '4005402',
                'responseMessage' => 'Invalid Mandatory Field X-TIMESTAMP',
            ], 400, ['X-Request-ID' => 'uat-missing-timestamp-001']),
        ]);

        $this->withToken(self::ServiceToken)
            ->postJson($this->missingTimestampUatUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.uat.scenario', 'create_order_missing_x_timestamp')
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.request.x_timestamp_sent', false)
            ->assertJsonPath('data.uat.received.http_status', 400)
            ->assertJsonPath('data.uat.received.response_code', '4005402')
            ->assertJsonPath('data.uat.received.response_message', 'Invalid Mandatory Field X-TIMESTAMP')
            ->assertJsonPath('data.uat.received.request_id', 'uat-missing-timestamp-001')
            ->assertDontSee(DanaSandboxKeyMaterialFixture::PrivateKey);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('dana_sandbox_test_runs', 0);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ! $request->hasHeader('X-TIMESTAMP')
            && $request->hasHeader('X-SIGNATURE')
            && $request->hasHeader('X-PARTNER-ID', 'partner-sandbox-001'));
    }

    public function test_invalid_amount_format_uat_uses_sandbox_credentials_without_creating_a_payment(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        Http::preventStrayRequests();
        Http::fake([
            'https://dana-sandbox.test/payment-gateway/v1.0/debit/payment-host-to-host.htm' => Http::response([
                'responseCode' => '4005401',
                'responseMessage' => 'Invalid Field Format amount',
            ], 400, ['X-Request-ID' => 'uat-invalid-amount-001']),
        ]);

        $this->withToken(self::ServiceToken)
            ->postJson($this->invalidAmountFormatUatUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.uat.scenario', 'create_order_invalid_amount_format')
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.request.amount_format_valid', false)
            ->assertJsonPath('data.uat.received.http_status', 400)
            ->assertJsonPath('data.uat.received.response_code', '4005401')
            ->assertJsonPath('data.uat.received.response_message', 'Invalid Field Format amount')
            ->assertJsonPath('data.uat.received.request_id', 'uat-invalid-amount-001')
            ->assertDontSee(DanaSandboxKeyMaterialFixture::PrivateKey);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('dana_sandbox_test_runs', 0);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('X-TIMESTAMP')
            && $request->hasHeader('X-SIGNATURE')
            && $request->hasHeader('X-PARTNER-ID', 'partner-sandbox-001')
            && json_decode($request->body(), true)['amount']['value'] === 'INVALID');
    }

    public function test_inconsistent_request_uat_sends_two_orders_and_expects_4045418(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        Http::preventStrayRequests();

        $callCount = 0;
        $capturedRequests = [];
        Http::fake([
            'https://dana-sandbox.test/payment-gateway/v1.0/debit/payment-host-to-host.htm' => function (Request $request) use (&$callCount, &$capturedRequests) {
                $callCount++;
                $capturedRequests[] = json_decode($request->body(), true);
                if ($callCount === 1) {
                    return Http::response([
                        'responseCode' => '2005400',
                        'responseMessage' => 'Successful',
                        'referenceNo' => 'DANA-ORDER-INC-001',
                    ], 200, ['X-Request-ID' => 'uat-inc-req-001']);
                }

                return Http::response([
                    'responseCode' => '4045418',
                    'responseMessage' => 'Inconsistent Request',
                ], 404, ['X-Request-ID' => 'uat-inc-req-002']);
            },
        ]);

        $this->withToken(self::ServiceToken)
            ->postJson($this->inconsistentRequestUatUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.uat.scenario', 'create_order_inconsistent_request')
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.first_request.response_code', '2005400')
            ->assertJsonPath('data.uat.first_request.amount', '100000.00')
            ->assertJsonPath('data.uat.second_request.response_code', '4045418')
            ->assertJsonPath('data.uat.second_request.response_message', 'Inconsistent Request')
            ->assertJsonPath('data.uat.second_request.amount', '200000.00')
            ->assertDontSee(DanaSandboxKeyMaterialFixture::PrivateKey);

        $this->assertSame(2, $callCount);
        $this->assertSame($capturedRequests[0]['partnerReferenceNo'], $capturedRequests[1]['partnerReferenceNo']);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $capturedRequests[0]['partnerReferenceNo']);
        $this->assertSame('REDIRECT', $capturedRequests[0]['additionalInfo']['order']['scenario']);
        $this->assertSame('REDIRECT', $capturedRequests[1]['additionalInfo']['order']['scenario']);
        $this->assertSame('100000.00', $capturedRequests[0]['amount']['value']);
        $this->assertSame('200000.00', $capturedRequests[1]['amount']['value']);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('dana_sandbox_test_runs', 0);
    }

    public function test_inconsistent_request_uat_safely_records_diagnostic_when_first_order_fails(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        Http::preventStrayRequests();

        Http::fake([
            'https://dana-sandbox.test/payment-gateway/v1.0/debit/payment-host-to-host.htm' => Http::response([
                'responseCode' => '4005401',
                'responseMessage' => 'Invalid Field Format',
            ], 400, ['X-Request-ID' => 'uat-diag-err-001']),
        ]);

        $this->withToken(self::ServiceToken)
            ->postJson($this->inconsistentRequestUatUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', false)
            ->assertJsonPath('data.uat.first_request.response_code', '4005401')
            ->assertJsonPath('data.uat.first_request.response_message', 'Invalid Field Format')
            ->assertJsonPath('data.uat.received.response_code', '4005401')
            ->assertDontSee(DanaSandboxKeyMaterialFixture::PrivateKey);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('dana_sandbox_test_runs', 0);
    }

    public function test_arm_sandbox_webhook_simulated_500_arms_pending_transaction_and_stores_cache_key(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'sandbox',
            'status' => 'pending',
            'metadata' => ['udev_sandbox_tester' => true],
        ]);

        $this->withToken(self::ServiceToken)
            ->postJson($this->armWebhook500UatUrl($merchant), ['payment_id' => $payment->public_id])
            ->assertOk()
            ->assertJsonPath('data.uat.scenario', 'finish_notify_simulated_500')
            ->assertJsonPath('data.uat.armed', true)
            ->assertJsonPath('data.uat.payment_id', $payment->public_id)
            ->assertJsonPath('data.uat.expected.http_status', 500)
            ->assertJsonPath('data.uat.expected.response_code', '5005601')
            ->assertDontSee(DanaSandboxKeyMaterialFixture::PrivateKey);

        $cacheKey = "dana:uat:simulate_notify_500:merchant:{$merchant->id}:payment:{$payment->id}";
        $this->assertTrue(Cache::has($cacheKey));
        $cachedData = Cache::get($cacheKey);
        $this->assertSame($merchant->id, $cachedData['merchant_id']);
        $this->assertSame($payment->id, $cachedData['payment_id']);
        $this->assertSame($payment->public_id, $cachedData['payment_public_id']);
    }

    public function test_creates_sandbox_checkout_through_existing_gateway_and_never_marks_it_paid(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        $returnContext = str_repeat('A', 43);
        Http::preventStrayRequests();
        Http::fake([
            'https://dana-sandbox.test/*' => Http::response([
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-SANDBOX-ORDER-001',
                'webRedirectUrl' => 'https://checkout.sandbox.dana.id/session/tester-001',
            ]),
        ]);

        $response = $this->withToken(self::ServiceToken)
            ->postJson($this->transactionUrl($merchant), ['amount_minor' => 10_000, 'payment_method' => 'dana_wallet', 'return_context' => $returnContext], ['Idempotency-Key' => 'sandbox-tester-transaction-001'])
            ->assertCreated()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.authentication.status', 'confirmed_by_checkout_response')
            ->assertJsonPath('data.transaction.status', 'pending')
            ->assertJsonPath('data.transaction.checkout_available', true)
            ->assertJsonPath('data.transaction.checkout_url', 'https://checkout.sandbox.dana.id/session/tester-001')
            ->assertJsonPath('data.return_context', $returnContext);

        $paymentId = $response->json('data.transaction.id');
        $this->assertIsString($paymentId);
        $this->assertDatabaseHas('payments', [
            'public_id' => $paymentId,
            'merchant_id' => $merchant->id,
            'environment' => 'sandbox',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('dana_sandbox_test_runs', [
            'merchant_id' => $merchant->id,
            'operation' => 'transaction_created',
            'status' => 'succeeded',
        ]);
        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return $request->url() === 'https://dana-sandbox.test/payment-gateway/v1.0/debit/payment-host-to-host.htm'
                && $request->hasHeader('X-PARTNER-ID', 'partner-sandbox-001')
                && $request->hasHeader('CHANNEL-ID', 'channel-sandbox-001')
                && ($payload['urlParams'][0]['url'] ?? null) === 'https://merchant.test/dana/return?context='.str_repeat('A', 43)
                && ! str_contains($request->body(), DanaSandboxKeyMaterialFixture::PrivateKey);
        });
    }

    public function test_transaction_retry_with_identical_idempotency_key_reuses_checkout_without_second_create_order(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        $idempotencyKey = 'sandbox-tester-retry-identical-001';
        $payload = ['amount_minor' => 10_000, 'payment_method' => 'dana_wallet', 'return_context' => str_repeat('R', 43)];
        Http::preventStrayRequests();
        Http::fake([
            'https://dana-sandbox.test/*' => Http::response([
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-SANDBOX-RETRY-001',
                'webRedirectUrl' => 'https://checkout.sandbox.dana.id/session/retry-001',
            ]),
        ]);

        $first = $this->withToken(self::ServiceToken)
            ->postJson($this->transactionUrl($merchant), $payload, ['Idempotency-Key' => $idempotencyKey])
            ->assertCreated();
        $second = $this->withToken(self::ServiceToken)
            ->postJson($this->transactionUrl($merchant), $payload, ['Idempotency-Key' => $idempotencyKey])
            ->assertCreated();

        $this->assertSame($first->json('data.transaction.id'), $second->json('data.transaction.id'));
        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
    }

    public function test_transaction_retry_with_changed_payload_is_rejected_without_second_create_order(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        $idempotencyKey = 'sandbox-tester-retry-changed-001';
        Http::preventStrayRequests();
        Http::fake([
            'https://dana-sandbox.test/*' => Http::response([
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-SANDBOX-CHANGED-001',
                'webRedirectUrl' => 'https://checkout.sandbox.dana.id/session/changed-001',
            ]),
        ]);

        $this->withToken(self::ServiceToken)
            ->postJson($this->transactionUrl($merchant), ['amount_minor' => 10_000, 'payment_method' => 'dana_wallet'], ['Idempotency-Key' => $idempotencyKey])
            ->assertCreated();

        $this->withToken(self::ServiceToken)
            ->postJson($this->transactionUrl($merchant), ['amount_minor' => 20_000, 'payment_method' => 'dana_wallet'], ['Idempotency-Key' => $idempotencyKey])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Idempotency-Key sudah digunakan untuk transaksi Sandbox yang berbeda.');

        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
    }

    public function test_each_supported_sandbox_method_creates_an_isolated_transaction_with_its_own_action_key(): void
    {
        $merchant = $this->managedMerchant();
        $config = $this->validConfiguration($merchant);
        $credentials = $config->encrypted_credentials;
        $credentials['external_store_id'] = 'sandbox-store-001';
        $config->update(['encrypted_credentials' => $credentials]);
        $requests = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$requests): array {
            $requests[] = $request->data();
            $payMethod = $request->data()['payOptionDetails'][0]['payMethod'] ?? null;

            return [
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-SANDBOX-METHOD-'.count($requests),
                'webRedirectUrl' => $payMethod === 'BALANCE' ? 'https://checkout.sandbox.dana.id/session/method-'.count($requests) : null,
                'additionalInfo' => $payMethod === 'BALANCE' ? [] : ['paymentCode' => 'PAYMENT-CODE-'.count($requests)],
            ];
        });
        $methods = [
            'dana_wallet' => ['BALANCE', ''],
            'qris' => ['NETWORK_PAY', 'NETWORK_PAY_PG_QRIS'],
            'va_bri' => ['VIRTUAL_ACCOUNT', 'VIRTUAL_ACCOUNT_BRI'],
            'va_pani' => ['VIRTUAL_ACCOUNT', 'VIRTUAL_ACCOUNT_PANI'],
            'va_cimb' => ['VIRTUAL_ACCOUNT', 'VIRTUAL_ACCOUNT_CIMB'],
            'va_btpn' => ['VIRTUAL_ACCOUNT', 'VIRTUAL_ACCOUNT_BTPN'],
        ];

        foreach ($methods as $method => [$payMethod, $payOption]) {
            $idempotencyKey = 'sandbox-tester-method-'.$method.'-001';
            $payload = ['amount_minor' => 10_000, 'payment_method' => $method];

            $first = $this->withToken(self::ServiceToken)
                ->postJson($this->transactionUrl($merchant), $payload, ['Idempotency-Key' => $idempotencyKey])
                ->assertCreated();
            $this->assertSame($method, $first->json('data.transaction.payment_instruction.method'));
            $request = $requests[array_key_last($requests)];
            $this->assertSame($payMethod, $request['payOptionDetails'][0]['payMethod']);
            $this->assertSame($payOption, $request['payOptionDetails'][0]['payOption']);
        }

        $this->assertDatabaseCount('payments', count($methods));
        Http::assertSentCount(count($methods));
    }

    public function test_qris_rejects_missing_external_store_before_calling_dana(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        Http::preventStrayRequests();

        $this->withToken(self::ServiceToken)
            ->postJson($this->transactionUrl($merchant), ['amount_minor' => 10_000, 'payment_method' => 'qris'], ['Idempotency-Key' => 'sandbox-tester-qris-no-store-001'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.');

        Http::assertNothingSent();
        $this->assertDatabaseHas('dana_sandbox_test_runs', [
            'merchant_id' => $merchant->id,
            'operation' => 'transaction_created',
            'status' => 'failed',
            'message' => 'QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.',
        ]);
    }

    public function test_status_and_history_are_isolated_to_sandbox_tester_payments_of_the_managed_merchant(): void
    {
        $merchant = $this->managedMerchant(['code' => 'udev-one']);
        $otherMerchant = $this->managedMerchant(['code' => 'udev-two']);
        $ownPayment = Payment::factory()->for($merchant)->create([
            'status' => 'pending',
            'metadata' => ['udev_sandbox_tester' => true, 'udev_sandbox_return_context' => str_repeat('B', 43)],
        ]);
        $otherPayment = Payment::factory()->for($otherMerchant)->create([
            'status' => 'pending',
            'metadata' => ['udev_sandbox_tester' => true, 'udev_sandbox_return_context' => str_repeat('C', 43)],
        ]);
        DanaSandboxTestRun::factory()->for($merchant)->for($ownPayment)->create();
        DanaSandboxTestRun::factory()->for($otherMerchant)->for($otherPayment)->create();

        $this->withToken(self::ServiceToken)
            ->getJson($this->transactionStatusUrl($merchant, $ownPayment))
            ->assertOk()
            ->assertJsonPath('data.transaction.id', $ownPayment->public_id)
            ->assertJsonPath('data.transaction.status', 'pending');
        $this->assertDatabaseHas('payments', ['id' => $ownPayment->id, 'status' => 'pending']);

        $this->withToken(self::ServiceToken)
            ->getJson($this->transactionStatusUrl($merchant, $otherPayment))
            ->assertNotFound();

        $this->withToken(self::ServiceToken)
            ->getJson($this->transactionUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.0.merchant_code', 'udev-one')
            ->assertDontSee('udev-two');
    }

    public function test_query_payment_uses_signed_qris_sandbox_request_and_never_changes_local_status(): void
    {
        $merchant = $this->managedMerchant();
        $config = $this->validConfiguration($merchant);
        $credentials = $config->encrypted_credentials;
        $credentials['external_store_id'] = 'sandbox-store-001';
        $config->update(['encrypted_credentials' => $credentials]);
        $payment = Payment::factory()->for($merchant)->create([
            'status' => 'pending',
            'partner_reference_no' => 'Q'.str_repeat('C', 24),
            'metadata' => ['udev_sandbox_tester' => true, 'udev_sandbox_payment_method' => 'qris'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://dana-sandbox.test/payment-gateway/v1.0/debit/status.htm' => Http::response([
                'responseCode' => '2005500',
                'latestTransactionStatus' => '00',
                'requestID' => 'dana-query-001',
            ]),
        ]);

        $this->withToken(self::ServiceToken)
            ->getJson($this->danaStatusUrl($merchant, $payment))
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.payment_id', $payment->public_id)
            ->assertJsonPath('data.local_status', 'pending')
            ->assertJsonPath('data.dana_status.response_code', '2005500')
            ->assertJsonPath('data.dana_status.latest_transaction_status', '00')
            ->assertJsonPath('data.dana_status.request_id', 'dana-query-001');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'paid_at' => null]);
        $this->assertDatabaseMissing('payment_events', ['payment_id' => $payment->id, 'event_type' => 'payment.status_changed']);
        $this->assertDatabaseHas('dana_sandbox_test_runs', [
            'merchant_id' => $merchant->id,
            'payment_id' => $payment->id,
            'operation' => 'dana_payment_status_queried',
            'status' => 'succeeded',
        ]);
        Http::assertSent(function (Request $request) use ($payment): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return $request->method() === 'POST'
                && $request->url() === 'https://dana-sandbox.test/payment-gateway/v1.0/debit/status.htm'
                && $request->hasHeader('X-SIGNATURE')
                && $request->hasHeader('X-PARTNER-ID', 'partner-sandbox-001')
                && $request->hasHeader('CHANNEL-ID', 'channel-sandbox-001')
                && ($payload['originalPartnerReferenceNo'] ?? null) === $payment->partner_reference_no
                && ($payload['serviceCode'] ?? null) === '54'
                && ($payload['merchantId'] ?? null) === 'merchant-sandbox-001'
                && ($payload['externalStoreId'] ?? null) === 'sandbox-store-001';
        });
    }

    public function test_query_payment_supports_dana_wallet_expired_sandbox_payment_and_remains_read_only(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        $payment = Payment::factory()->for($merchant)->create([
            'status' => 'expired',
            'partner_reference_no' => 'DW'.str_repeat('F', 23),
            'metadata' => ['udev_sandbox_tester' => true, 'udev_sandbox_payment_method' => 'dana_wallet'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://dana-sandbox.test/payment-gateway/v1.0/debit/status.htm' => Http::response([
                'responseCode' => '2005500',
                'latestTransactionStatus' => '05',
                'requestID' => 'dana-wallet-query-005',
            ]),
        ]);

        $this->withToken(self::ServiceToken)
            ->getJson($this->danaStatusUrl($merchant, $payment))
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.payment_id', $payment->public_id)
            ->assertJsonPath('data.local_status', 'expired')
            ->assertJsonPath('data.dana_status.http_status', 200)
            ->assertJsonPath('data.dana_status.response_code', '2005500')
            ->assertJsonPath('data.dana_status.latest_transaction_status', '05')
            ->assertJsonPath('data.dana_status.request_id', 'dana-wallet-query-005');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'expired', 'paid_at' => null]);
        $this->assertDatabaseMissing('payment_events', ['payment_id' => $payment->id]);
        $this->assertDatabaseHas('dana_sandbox_test_runs', [
            'merchant_id' => $merchant->id,
            'payment_id' => $payment->id,
            'operation' => 'dana_payment_status_queried',
            'status' => 'succeeded',
        ]);
        Http::assertSent(function (Request $request) use ($payment): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return $request->method() === 'POST'
                && $request->url() === 'https://dana-sandbox.test/payment-gateway/v1.0/debit/status.htm'
                && $request->hasHeader('X-SIGNATURE')
                && $request->hasHeader('X-PARTNER-ID', 'partner-sandbox-001')
                && $request->hasHeader('CHANNEL-ID', 'channel-sandbox-001')
                && ($payload['originalPartnerReferenceNo'] ?? null) === $payment->partner_reference_no
                && ($payload['serviceCode'] ?? null) === '54'
                && ($payload['merchantId'] ?? null) === 'merchant-sandbox-001'
                && ! array_key_exists('externalStoreId', $payload);
        });
    }

    public function test_query_payment_supports_expired_sandbox_payment_with_cancelled_debit_status_05_and_never_mutates_local_status(): void
    {
        $merchant = $this->managedMerchant();
        $config = $this->validConfiguration($merchant);
        $credentials = $config->encrypted_credentials;
        $credentials['external_store_id'] = 'sandbox-store-001';
        $config->update(['encrypted_credentials' => $credentials]);
        $payment = Payment::factory()->for($merchant)->create([
            'status' => 'expired',
            'partner_reference_no' => 'Q'.str_repeat('E', 24),
            'metadata' => ['udev_sandbox_tester' => true, 'udev_sandbox_payment_method' => 'qris'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://dana-sandbox.test/payment-gateway/v1.0/debit/status.htm' => Http::response([
                'responseCode' => '2005500',
                'latestTransactionStatus' => '05',
                'requestID' => 'dana-query-expired-005',
            ]),
        ]);

        $this->withToken(self::ServiceToken)
            ->getJson($this->danaStatusUrl($merchant, $payment))
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.payment_id', $payment->public_id)
            ->assertJsonPath('data.local_status', 'expired')
            ->assertJsonPath('data.dana_status.http_status', 200)
            ->assertJsonPath('data.dana_status.response_code', '2005500')
            ->assertJsonPath('data.dana_status.latest_transaction_status', '05')
            ->assertJsonPath('data.dana_status.request_id', 'dana-query-expired-005');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'expired', 'paid_at' => null]);
        $this->assertDatabaseMissing('payment_events', ['payment_id' => $payment->id]);
        $this->assertDatabaseHas('dana_sandbox_test_runs', [
            'merchant_id' => $merchant->id,
            'payment_id' => $payment->id,
            'operation' => 'dana_payment_status_queried',
            'status' => 'succeeded',
        ]);
        Http::assertSent(function (Request $request) use ($payment): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return $request->method() === 'POST'
                && $request->url() === 'https://dana-sandbox.test/payment-gateway/v1.0/debit/status.htm'
                && $request->hasHeader('X-SIGNATURE')
                && $request->hasHeader('X-PARTNER-ID', 'partner-sandbox-001')
                && $request->hasHeader('CHANNEL-ID', 'channel-sandbox-001')
                && ($payload['originalPartnerReferenceNo'] ?? null) === $payment->partner_reference_no
                && ($payload['serviceCode'] ?? null) === '54'
                && ($payload['merchantId'] ?? null) === 'merchant-sandbox-001'
                && ($payload['externalStoreId'] ?? null) === 'sandbox-store-001';
        });
    }

    private function managedMerchant(array $attributes = []): Merchant
    {
        $merchant = Merchant::factory()->create($attributes);
        InternalMerchantProvisioningRequest::factory()->for($merchant)->create([
            'service_id' => 'udev-billing',
            'environment' => 'sandbox',
        ]);

        return $merchant;
    }

    private function validConfiguration(Merchant $merchant): MerchantPaymentConfig
    {
        return MerchantPaymentConfig::factory()->for($merchant)->create([
            'partner_id' => 'partner-sandbox-001',
            'callback_url' => 'https://merchant.test/dana/return',
            'enabled' => true,
            'encrypted_credentials' => $this->credentials(),
        ]);
    }

    /** @return array<string, string> */
    private function credentials(): array
    {
        return [
            'merchant_id' => 'merchant-sandbox-001',
            'private_key' => DanaSandboxKeyMaterialFixture::PrivateKey,
            'dana_webhook_public_key' => DanaSandboxKeyMaterialFixture::PublicKey,
            'origin' => 'https://merchant.test',
            'channel_id' => 'channel-sandbox-001',
            'notification_url' => 'https://merchant.test/dana/notification',
            'mcc' => '7392',
        ];
    }

    private function validationUrl(Merchant $merchant): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/sandbox-tester/validation";
    }

    private function transactionUrl(Merchant $merchant): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/sandbox-tester/transactions";
    }

    private function missingTimestampUatUrl(Merchant $merchant): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/sandbox-tester/uat/create-order-missing-timestamp";
    }

    private function invalidAmountFormatUatUrl(Merchant $merchant): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/sandbox-tester/uat/create-order-invalid-amount-format";
    }

    private function inconsistentRequestUatUrl(Merchant $merchant): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/sandbox-tester/uat/create-order-inconsistent-request";
    }

    private function armWebhook500UatUrl(Merchant $merchant): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/sandbox-tester/uat/arm-webhook-500";
    }

    private function transactionStatusUrl(Merchant $merchant, Payment $payment): string
    {
        return $this->transactionUrl($merchant)."/{$payment->public_id}/status";
    }

    private function danaStatusUrl(Merchant $merchant, Payment $payment): string
    {
        return $this->transactionUrl($merchant)."/{$payment->public_id}/dana-status";
    }
}
