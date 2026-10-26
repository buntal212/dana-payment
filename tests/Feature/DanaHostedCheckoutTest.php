<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Services\DanaAmountFormatter;
use App\Services\DanaPaymentGateway;
use App\Services\DanaSnapSignatureService;
use App\Services\MerchantApiKeyService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DanaHostedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const DANA_CALLBACK_PATH = '/api/v1/webhooks/dana';

    private RecordingDanaSnapSignatureService $signatureService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signatureService = new RecordingDanaSnapSignatureService;
        $this->app->instance(DanaSnapSignatureService::class, $this->signatureService);
    }

    public function test_create_payment_sends_signed_create_order_and_returns_checkout_url(): void
    {
        [$merchant, $apiKey] = $this->merchantWithDanaConfig();
        $sentBody = null;
        Log::spy();
        Http::fake(function (HttpRequest $request) use (&$sentBody): array {
            $sentBody = $request->body();
            $header = static fn (string $name): ?string => $request->header($name)[0] ?? null;
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://api.sandbox.dana.id/payment-gateway/v1.0/debit/payment-host-to-host.htm', $request->url());
            $this->assertNotEmpty($header('X-SIGNATURE'));
            $this->assertStringStartsWith('application/json', (string) $header('Content-Type'));
            $this->assertSame('partner-dana-001', $header('X-PARTNER-ID'));
            $this->assertSame('95221', $header('CHANNEL-ID'));
            $this->assertSame('merchant.example', $header('ORIGIN'));
            $this->assertNotEmpty($header('X-EXTERNAL-ID'));
            $this->assertSame('merchant-dana-001', $request->data()['merchantId']);
            $this->assertSame(['value' => '10000.00', 'currency' => 'IDR'], $request->data()['amount']);
            $this->assertSame('https://merchant.example/payment-return', $request->data()['urlParams'][0]['url']);

            return ['responseCode' => '2005400', 'referenceNo' => 'DANA-ORDER-001', 'webRedirectUrl' => 'https://checkout.sandbox.dana.id/session/001'];
        });

        $this->withToken($apiKey)->postJson('/api/v1/payments', [
            'merchant_order_id' => 'order-dana-001',
            'amount_minor' => 10_000,
            'currency' => 'IDR',
        ], ['Idempotency-Key' => 'dana-checkout-001'])
            ->assertSuccessful()
            ->assertJsonPath('data.checkout_url', 'https://checkout.sandbox.dana.id/session/001');

        $this->assertIsString($sentBody);
        $this->assertSame($sentBody, $this->signatureService->lastSignatureInput['json_body']);
        $this->assertSame('POST', $this->signatureService->lastSignatureInput['method']);
        $this->assertSame('/payment-gateway/v1.0/debit/payment-host-to-host.htm', $this->signatureService->lastSignatureInput['relative_path']);
        $this->assertStringNotContainsString('\\/', $sentBody);
        $this->assertDatabaseHas('payments', ['merchant_id' => $merchant->id, 'provider_order_id' => 'DANA-ORDER-001', 'status' => 'pending']);
        Log::shouldHaveReceived('debug')->withArgs(function (string $message, array $context): bool {
            return $message === 'Diagnostik signature DANA Create Order.'
                && ! array_key_exists('private_key', $context)
                && ! array_key_exists('signature', $context)
                && ! array_key_exists('body', $context)
                && ! in_array('test-private-key', $context, true);
        })->once();
        Http::assertSentCount(1);
    }

    public function test_sandbox_tester_checkout_appends_only_its_valid_return_context_to_registered_callback(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create([
            'merchant_order_id' => 'sandbox-tester-return-context',
            'metadata' => [
                'udev_sandbox_tester' => true,
                'udev_sandbox_return_context' => str_repeat('A', 43),
            ],
        ]);
        Http::fake(function (HttpRequest $request): array {
            $this->assertSame(
                'https://merchant.example/payment-return?context='.str_repeat('A', 43),
                $request->data()['urlParams'][0]['url'],
            );

            return ['responseCode' => '2005400', 'referenceNo' => 'DANA-ORDER-SANDBOX-RESULT', 'webRedirectUrl' => 'https://checkout.sandbox.dana.id/session/sandbox-result'];
        });

        app(DanaPaymentGateway::class)->startCheckout($payment, $merchant->paymentConfigs()->firstOrFail());
    }

    public function test_sandbox_custom_checkout_only_uses_the_documented_wallet_qris_and_va_options(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $config = $merchant->paymentConfigs()->firstOrFail();
        $credentials = $config->encrypted_credentials;
        $credentials['external_store_id'] = 'sandbox-store-001';
        $config->update(['encrypted_credentials' => $credentials]);
        $requests = [];
        Http::fake(function (HttpRequest $request) use (&$requests): array {
            $requests[] = $request->data();
            $method = $request->data()['payOptionDetails'][0]['payMethod'] ?? null;

            return [
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-CUSTOM-'.count($requests),
                'webRedirectUrl' => $method === 'BALANCE' ? 'https://checkout.sandbox.dana.id/session/wallet' : null,
                'additionalInfo' => $method === 'BALANCE' ? [] : ['paymentCode' => 'PAYMENT-CODE-'.count($requests)],
            ];
        });
        $methods = [
            'dana_wallet' => ['BALANCE', '', 'redirect'],
            'qris' => ['NETWORK_PAY', 'NETWORK_PAY_PG_QRIS', 'payment_code'],
            'va_bri' => ['VIRTUAL_ACCOUNT', 'VIRTUAL_ACCOUNT_BRI', 'virtual_account'],
            'va_pani' => ['VIRTUAL_ACCOUNT', 'VIRTUAL_ACCOUNT_PANI', 'virtual_account'],
            'va_cimb' => ['VIRTUAL_ACCOUNT', 'VIRTUAL_ACCOUNT_CIMB', 'virtual_account'],
            'va_btpn' => ['VIRTUAL_ACCOUNT', 'VIRTUAL_ACCOUNT_BTPN', 'virtual_account'],
        ];

        foreach ($methods as $method => [$payMethod, $payOption, $instructionType]) {
            $payment = Payment::factory()->for($merchant)->create([
                'partner_reference_no' => $method === 'qris' ? 'Q'.str_repeat('A', 24) : null,
                'metadata' => [
                    'udev_sandbox_tester' => true,
                    'udev_sandbox_payment_method' => $method,
                ],
            ]);

            $result = app(DanaPaymentGateway::class)->startCheckout($payment, $config);

            $this->assertSame($instructionType, $result['payment_instruction']['type']);
            $this->assertSame($payMethod, $requests[array_key_last($requests)]['payOptionDetails'][0]['payMethod']);
            $this->assertSame($payOption, $requests[array_key_last($requests)]['payOptionDetails'][0]['payOption']);
        }

        $qrisRequest = $requests[1];
        $this->assertSame('sandbox-store-001', $qrisRequest['externalStoreId']);
        $this->assertSame('Q'.str_repeat('A', 24), $qrisRequest['partnerReferenceNo']);
        $this->assertLessThanOrEqual(25, strlen($qrisRequest['partnerReferenceNo']));
    }

    public function test_qris_partner_reference_is_short_persisted_and_idempotent(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $attributes = [
            'merchant_order_id' => 'qris-partner-reference-001',
            'amount_minor' => 10_000,
            'currency' => 'IDR',
            'idempotency_key' => 'qris-partner-reference-key-001',
            'metadata' => [
                'udev_sandbox_tester' => true,
                'udev_sandbox_payment_method' => 'qris',
            ],
        ];

        $first = app(PaymentService::class)->createPending($merchant, $attributes);
        $second = app(PaymentService::class)->createPending($merchant, $attributes);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->partner_reference_no, $second->partner_reference_no);
        $this->assertMatchesRegularExpression('/\AQ[A-Fa-f0-9]{24}\z/', (string) $first->partner_reference_no);
        $this->assertLessThanOrEqual(25, strlen((string) $first->partner_reference_no));
    }

    public function test_invalid_signature_response_from_dana_does_not_create_a_checkout_or_mark_payment_paid(): void
    {
        [$merchant, $apiKey] = $this->merchantWithDanaConfig();
        Http::fake([
            'https://api.sandbox.dana.id/*' => Http::response([
                'responseCode' => '4015400',
                'responseMessage' => 'Unauthorized. Invalid Signature.',
            ], 401, ['X-Request-ID' => 'dana-request-001']),
        ]);

        $this->withToken($apiKey)->postJson('/api/v1/payments', [
            'merchant_order_id' => 'order-dana-invalid-signature',
            'amount_minor' => 10_000,
            'currency' => 'IDR',
        ], ['Idempotency-Key' => 'dana-checkout-invalid-signature'])
            ->assertStatus(503);

        $this->assertDatabaseHas('payments', ['merchant_id' => $merchant->id, 'merchant_order_id' => 'order-dana-invalid-signature', 'status' => 'pending', 'provider_order_id' => null, 'checkout_url' => null]);
    }

    public function test_finish_notify_signature_uses_actual_callback_path_by_default(): void
    {
        $this->assertSame(self::DANA_CALLBACK_PATH, config('services.dana.webhook_relative_path'));
    }

    public function test_finish_notify_uses_received_callback_path_without_query_string_for_signature(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-CALLBACK-PATH']);

        $this->postWebhook($this->finishNotifyPayload($payment, '00'), headers: ['request_uri' => self::DANA_CALLBACK_PATH.'?utm_source=dana'])->assertOk();

        $this->assertSame(self::DANA_CALLBACK_PATH, $this->signatureService->lastVerificationInput['relative_path']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid']);
    }

    public function test_valid_finish_notify_marks_pending_payment_paid_and_duplicate_is_safe(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-002', 'amount_minor' => 25_000]);
        $payload = $this->finishNotifyPayload($payment, '00');
        Log::spy();

        $this->postWebhook($payload)->assertOk()->assertJsonPath('responseCode', '2005600');
        $this->postWebhook($payload)->assertOk()->assertJsonPath('responseCode', '2005600');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid']);
        $this->assertDatabaseCount('payment_webhooks', 1);
        $this->assertDatabaseCount('payment_events', 1);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_qris_finish_notify_resolves_the_persisted_short_partner_reference(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create([
            'partner_reference_no' => 'Q'.str_repeat('B', 24),
            'provider_order_id' => 'DANA-ORDER-QRIS-SHORT-REFERENCE',
            'metadata' => [
                'udev_sandbox_tester' => true,
                'udev_sandbox_payment_method' => 'qris',
            ],
        ]);
        $payload = $this->finishNotifyPayload($payment, '00');
        $payload['originalPartnerReferenceNo'] = $payment->partner_reference_no;

        $this->postWebhook($payload)->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid']);
    }

    public function test_finish_notify_without_original_partner_reference_is_rejected_without_changing_payment_status(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create([
            'metadata' => [
                'udev_sandbox_tester' => true,
                'udev_sandbox_payment_method' => 'qris',
            ],
            'provider_order_id' => 'DANA-ORDER-QRIS-MISSING-REFERENCE',
        ]);
        $payload = $this->finishNotifyPayload($payment, '00');
        unset($payload['originalPartnerReferenceNo']);

        $this->postWebhook($payload)->assertBadRequest();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
        $this->assertDatabaseHas('payment_webhooks', [
            'merchant_id' => null,
            'payment_id' => null,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'processing_status' => 'rejected',
            'error_code' => 'missing_reference',
        ]);
    }

    public function test_webhook_with_invalid_timestamp_is_rejected_and_reports_only_timestamp_failure(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-TIMESTAMP']);
        Log::spy();

        $this->postWebhook($this->finishNotifyPayload($payment, '00'), headers: ['timestamp' => '2026-10-11T10:01:00Z'])->assertBadRequest();

        $this->assertWebhookAuthenticationDiagnostics($payment, false, true, true, true, '2026-10-11T10:01:00Z');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_webhook_with_invalid_partner_id_is_rejected_and_reports_only_partner_failure(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-PARTNER']);
        Log::spy();

        $this->postWebhook($this->finishNotifyPayload($payment, '00'), headers: ['partner_id' => 'partner-dana-foreign'])->assertBadRequest();

        $this->assertWebhookAuthenticationDiagnostics($payment, true, false, true, true);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_webhook_with_invalid_merchant_id_is_rejected_and_reports_only_merchant_failure(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-MERCHANT']);
        $payload = $this->finishNotifyPayload($payment, '00');
        $payload['merchantId'] = 'merchant-dana-foreign';
        Log::spy();

        $this->postWebhook($payload)->assertBadRequest();

        $this->assertWebhookAuthenticationDiagnostics($payment, true, true, false, true);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_webhook_with_invalid_signature_is_rejected_and_reports_only_signature_failure(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-SIGNATURE']);
        Log::spy();

        $this->postWebhook($this->finishNotifyPayload($payment, '00'), base64_encode('not-a-valid-rsa-signature'))->assertBadRequest();

        $this->assertWebhookAuthenticationDiagnostics($payment, true, true, true, false);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_invalid_signature_or_mismatched_amount_is_rejected_without_changing_payment_status(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-003', 'amount_minor' => 50_000]);

        $this->postWebhook($this->finishNotifyPayload($payment, '00'), 'invalid-signature')->assertBadRequest();
        $this->postWebhook($this->finishNotifyPayload($payment, '00', '50001.00'))->assertBadRequest();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
        $this->assertDatabaseCount('payment_webhooks', 2);
    }

    public function test_wrong_dana_public_key_and_currency_mismatch_are_rejected(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-004', 'amount_minor' => 50_000]);
        $config = $merchant->paymentConfigs()->firstOrFail();
        $credentials = $config->encrypted_credentials;
        $credentials['dana_webhook_public_key'] = 'merchant-public-key-must-not-verify-webhook';
        $config->update(['encrypted_credentials' => $credentials]);

        $this->postWebhook($this->finishNotifyPayload($payment, '00'))->assertBadRequest();

        $credentials['dana_webhook_public_key'] = 'verified-dana-public-key';
        $config->update(['encrypted_credentials' => $credentials]);
        $payload = $this->finishNotifyPayload($payment, '00');
        $payload['amount']['currency'] = 'USD';
        $this->postWebhook($payload)->assertBadRequest();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_retry_after_rejected_delivery_can_process_the_payment_once(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-005']);
        $payload = $this->finishNotifyPayload($payment, '00');

        $this->postWebhook($payload, 'invalid-signature')->assertBadRequest();
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid']);
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseCount('payment_webhooks', 2);
    }

    public function test_pending_payment_can_expire_and_a_later_paid_status_is_rejected(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-006']);

        $this->postWebhook($this->finishNotifyPayload($payment, '05'))->assertOk();
        $this->postWebhook($this->finishNotifyPayload($payment, '00'))->assertBadRequest();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'expired']);
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseHas('payment_webhooks', ['payment_id' => $payment->id, 'processing_status' => 'rejected', 'error_code' => 'illegal_status_transition']);
    }

    public function test_webhook_cannot_update_a_payment_for_another_merchant(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $config = $merchant->paymentConfigs()->firstOrFail();
        $credentials = $config->encrypted_credentials;
        $credentials['merchant_id'] = 'merchant-dana-foreign';
        $config->update(['partner_id' => 'partner-dana-foreign', 'encrypted_credentials' => $credentials]);
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-007']);
        $payload = $this->finishNotifyPayload($payment, '00');

        // Header dan payload milik merchant lain tidak boleh memperbarui payment,
        // sekalipun partner reference-nya valid.
        $this->postWebhook($payload)->assertBadRequest();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    /** @return array{0: Merchant, 1: string} */
    private function merchantWithDanaConfig(): array
    {
        $merchant = Merchant::factory()->create();
        MerchantPaymentConfig::factory()->for($merchant)->create([
            'partner_id' => 'partner-dana-001',
            'callback_url' => 'https://merchant.example/payment-return',
            'enabled' => true,
            'encrypted_credentials' => [
                'merchant_id' => 'merchant-dana-001',
                'private_key' => 'test-private-key',
                'dana_webhook_public_key' => 'verified-dana-public-key',
                'origin' => 'merchant.example',
                'channel_id' => '95221',
                'notification_url' => 'https://merchant.example/api/v1/webhooks/dana',
                'mcc' => '5814',
            ],
        ]);
        $apiKey = app(MerchantApiKeyService::class)->create($merchant, 'dana-test', abilities: ['payments:*']);

        return [$merchant, $apiKey['api_key']];
    }

    /** @return array<string, mixed> */
    private function finishNotifyPayload(Payment $payment, string $status, ?string $amount = null): array
    {
        return [
            'originalPartnerReferenceNo' => $payment->public_id,
            'originalReferenceNo' => $payment->provider_order_id,
            'merchantId' => 'merchant-dana-001',
            'amount' => ['value' => $amount ?? app(DanaAmountFormatter::class)->formatIdr($payment->amount_minor), 'currency' => 'IDR'],
            'latestTransactionStatus' => $status,
            'createdTime' => '2026-10-11T10:00:00+07:00',
            'finishedTime' => '2026-10-11T10:01:00+07:00',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postWebhook(array $payload, ?string $signature = null, array $headers = []): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = $headers['timestamp'] ?? '2026-10-11T10:01:00+07:00';
        $callbackPath = $headers['signature_path'] ?? self::DANA_CALLBACK_PATH;
        $signature ??= app(DanaSnapSignatureService::class)->signTransactional('POST', $callbackPath, app(DanaSnapSignatureService::class)->minifyJson($body), $timestamp, 'test-private-key');

        return $this->call('POST', $headers['request_uri'] ?? self::DANA_CALLBACK_PATH, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_X_PARTNER_ID' => $headers['partner_id'] ?? 'partner-dana-001',
        ], $body);
    }

    private function assertWebhookAuthenticationDiagnostics(Payment $payment, bool $timestampValid, bool $partnerIdValid, bool $merchantIdValid, bool $signatureValid, string $receivedTimestamp = '2026-10-11T10:01:00+07:00'): void
    {
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($payment, $timestampValid, $partnerIdValid, $merchantIdValid, $signatureValid, $receivedTimestamp): bool {
            return $message === 'Webhook DANA gagal validasi autentikasi.'
                && $context['payment_id'] === $payment->getKey()
                && $context['payment_merchant_id'] === $payment->merchant_id
                && $context['received_timestamp'] === $receivedTimestamp
                && $context['timestamp_valid'] === $timestampValid
                && $context['partner_id_valid'] === $partnerIdValid
                && $context['merchant_id_valid'] === $merchantIdValid
                && $context['signature_valid'] === $signatureValid
                && $context['signature_base64_valid'] === true
                && $context['signature_decoded_bytes'] === 256
                && $context['public_key_valid'] === true
                && $context['public_key_bits'] === 2048
                && preg_match('/^[a-f0-9]{64}$/', $context['public_key_sha256']) === 1
                && $context['public_key_is_rsa'] === true
                && preg_match('/^[a-f0-9]{64}$/', $context['string_to_sign_sha256']) === 1
                && $context['openssl_verify_result'] === ($signatureValid ? 1 : 0)
                && $context['openssl_verification_completed'] === true
                && $context['relative_path'] === self::DANA_CALLBACK_PATH
                && is_int($context['raw_body_bytes'])
                && preg_match('/^[a-f0-9]{64}$/', $context['raw_body_sha256']) === 1
                && is_int($context['canonical_body_bytes'])
                && preg_match('/^[a-f0-9]{64}$/', $context['canonical_body_sha256']) === 1
                && is_bool($context['body_is_canonical'])
                && $context['body_changed_by_canonicalization'] === ! $context['body_is_canonical']
                && ! array_key_exists('raw_body', $context)
                && ! array_key_exists('signature', $context)
                && ! array_key_exists('private_key', $context)
                && ! array_key_exists('public_key', $context);
        })->once();
    }
}

class RecordingDanaSnapSignatureService extends DanaSnapSignatureService
{
    /** @var array{method: string, relative_path: string, json_body: string, timestamp: string}|null */
    public ?array $lastSignatureInput = null;

    /** @var array{method: string, relative_path: string, json_body: string, timestamp: string}|null */
    public ?array $lastVerificationInput = null;

    public function signTransactional(string $method, string $relativePath, string $jsonBody, string $timestamp, string $privateKey): string
    {
        $this->lastSignatureInput = [
            'method' => $method,
            'relative_path' => $relativePath,
            'json_body' => $jsonBody,
            'timestamp' => $timestamp,
        ];

        return 'valid-signature';
    }

    public function verifyTransactional(string $method, string $relativePath, string $jsonBody, string $timestamp, string $signature, string $publicKey): bool
    {
        return $signature === 'valid-signature' && $publicKey === 'verified-dana-public-key';
    }

    public function transactionalVerificationDiagnostics(string $method, string $relativePath, string $jsonBody, string $timestamp, string $signature, string $publicKey): array
    {
        $this->lastVerificationInput = [
            'method' => $method,
            'relative_path' => $relativePath,
            'json_body' => $jsonBody,
            'timestamp' => $timestamp,
        ];
        $signatureBase64Valid = $signature === 'valid-signature' || base64_decode($signature, true) !== false;
        $publicKeyValid = $publicKey === 'verified-dana-public-key';
        $signatureValid = $signature === 'valid-signature' && $publicKeyValid;

        return [
            'signature_valid' => $signatureValid,
            'signature_base64_valid' => $signatureBase64Valid,
            'signature_decoded_bytes' => 256,
            'public_key_valid' => $publicKeyValid,
            'public_key_bits' => $publicKeyValid ? 2048 : null,
            'public_key_sha256' => $publicKeyValid ? str_repeat('a', 64) : null,
            'public_key_is_rsa' => $publicKeyValid,
            'string_to_sign_sha256' => str_repeat('b', 64),
            'openssl_verify_result' => $publicKeyValid ? ($signatureValid ? 1 : 0) : null,
            'openssl_verification_completed' => $publicKeyValid,
        ];
    }

    public function verifyWithSigningPrivateKey(string $method, string $relativePath, string $jsonBody, string $timestamp, string $signature, string $privateKey): bool
    {
        return $signature === 'valid-signature' && $privateKey === 'test-private-key';
    }
}
