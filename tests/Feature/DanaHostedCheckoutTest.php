<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Services\DanaAmountFormatter;
use App\Services\DanaSnapSignatureService;
use App\Services\MerchantApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DanaHostedCheckoutTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_finish_notify_signature_uses_official_path_by_default(): void
    {
        $this->assertSame('/v1.0/debit/notify', config('services.dana.webhook_relative_path'));
    }

    public function test_valid_finish_notify_marks_pending_payment_paid_and_duplicate_is_safe(): void
    {
        [$merchant] = $this->merchantWithDanaConfig();
        $payment = Payment::factory()->for($merchant)->create(['provider_order_id' => 'DANA-ORDER-002', 'amount_minor' => 25_000]);
        $payload = $this->finishNotifyPayload($payment, '00');

        $this->postWebhook($payload)->assertOk()->assertJsonPath('responseCode', '2005600');
        $this->postWebhook($payload)->assertOk()->assertJsonPath('responseCode', '2005600');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid']);
        $this->assertDatabaseCount('payment_webhooks', 1);
        $this->assertDatabaseCount('payment_events', 1);
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
    private function postWebhook(array $payload, ?string $signature = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = '2026-10-11T10:01:00+07:00';
        $signature ??= app(DanaSnapSignatureService::class)->signTransactional('POST', (string) config('services.dana.webhook_relative_path'), app(DanaSnapSignatureService::class)->minifyJson($body), $timestamp, 'test-private-key');

        return $this->call('POST', '/api/v1/webhooks/dana', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_X_PARTNER_ID' => 'partner-dana-001',
        ], $body);
    }
}

class RecordingDanaSnapSignatureService extends DanaSnapSignatureService
{
    /** @var array{method: string, relative_path: string, json_body: string, timestamp: string}|null */
    public ?array $lastSignatureInput = null;

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

    public function verifyWithSigningPrivateKey(string $method, string $relativePath, string $jsonBody, string $timestamp, string $signature, string $privateKey): bool
    {
        return $signature === 'valid-signature' && $privateKey === 'test-private-key';
    }
}
