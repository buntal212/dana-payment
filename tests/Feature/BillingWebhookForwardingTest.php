<?php

namespace Tests\Feature;

use App\Models\BillingWebhookOutbox;
use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Services\DanaSnapSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\DanaSandboxKeyMaterialFixture;
use Tests\TestCase;

class BillingWebhookForwardingTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_BILLING_WEBHOOK_URL = 'http://127.0.0.1:8000/api/v1/payment-gateways/dana/webhook';

    private const string TEST_WEBHOOK_SECRET = 'udev-billing-shared-secret-key-64chars';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.udev_billing.webhook_url', self::TEST_BILLING_WEBHOOK_URL);
        config()->set('services.udev_billing.webhook_secret', self::TEST_WEBHOOK_SECRET);
        config()->set('services.udev_billing.timeout', 5);
        config()->set('services.udev_billing.connect_timeout', 2);
        config()->set('services.udev_billing.retry_backoff_seconds', 10);

        // Mock signature verification agar webhook SNAP valid
        $this->app->instance(DanaSnapSignatureService::class, new class extends DanaSnapSignatureService {
            public function transactionalVerificationDiagnostics(
                string $method,
                string $relativePath,
                string $jsonBody,
                string $timestamp,
                string $signature,
                string $publicKey,
                array $candidatePaths = [],
                ?string $fallbackPublicKey = null,
            ): array {
                return [
                    'signature_valid' => true,
                    'signature_base64_valid' => true,
                    'signature_decoded_bytes' => 256,
                    'public_key_valid' => true,
                    'public_key_bits' => 2048,
                    'public_key_sha256' => 'mocked',
                    'public_key_is_rsa' => true,
                    'string_to_sign_sha256' => 'mocked',
                    'openssl_verify_result' => 1,
                    'openssl_verification_completed' => true,
                ];
            }
        });
    }

    public function test_verified_paid_webhook_updates_payment_and_forwards_to_billing(): void
    {
        [$merchant, $config] = $this->createMerchantWithConfig('production');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'amount_minor' => 100_000,
            'currency' => 'IDR',
            'status' => 'pending',
            'merchant_order_id' => 'INV-202611-0001-A1',
        ]);

        $forwardRequestCaptured = null;
        Http::fake([
            self::TEST_BILLING_WEBHOOK_URL => function (ClientRequest $request) use (&$forwardRequestCaptured) {
                $forwardRequestCaptured = $request;

                return Http::response([
                    'status' => 'OK',
                    'message' => 'Settled successfully',
                ], 200);
            },
        ]);

        $rawBody = json_encode([
            'originalPartnerReferenceNo' => $payment->public_id,
            'originalReferenceNo' => 'DANA-REF-12345678',
            'latestTransactionStatus' => '00',
            'merchantId' => '216880000000000000001',
            'amount' => ['value' => '100000.00', 'currency' => 'IDR'],
            'finishedTime' => now()->toISOString(),
        ], JSON_THROW_ON_ERROR);

        $response = $this->postJson('/api/v1/webhooks/dana', json_decode($rawBody, true), [
            'X-TIMESTAMP' => now('Asia/Jakarta')->format('Y-m-d\TH:i:sP'),
            'X-PARTNER-ID' => 'test-partner-id',
            'X-SIGNATURE' => 'mocked-rsa-signature',
            'X-EXTERNAL-ID' => (string) $payment->public_id,
        ]);

        $response->assertOk()
            ->assertJsonPath('responseCode', '2005600')
            ->assertJsonPath('responseMessage', 'Successful');

        // 1. Payment di dana-payment harus berubah menjadi paid
        $refreshed = $payment->fresh();
        $this->assertSame('paid', $refreshed->status);
        $this->assertNotNull($refreshed->paid_at);
        $this->assertSame('DANA-REF-12345678', $refreshed->provider_order_id);

        // 2. HTTP call ke UDEV Billing harus terkirim
        $this->assertNotNull($forwardRequestCaptured, 'Request forwarding ke UDEV Billing tidak terpanggil.');
        $this->assertSame(self::TEST_WEBHOOK_SECRET, $forwardRequestCaptured->header('X-Webhook-Secret')[0]);
        $this->assertSame('INV-202611-0001-A1', $forwardRequestCaptured['merchant_order_id']);
        $this->assertSame((string) $payment->public_id, $forwardRequestCaptured['originalReferenceNo']);
        $this->assertSame('paid', $forwardRequestCaptured['status']);
        $this->assertSame('00', $forwardRequestCaptured['latestTransactionStatus']);
        $this->assertSame(100000, $forwardRequestCaptured['amount_minor']);

        // 3. Outbox status harus delivered
        $outbox = BillingWebhookOutbox::query()->where('payment_id', $payment->id)->first();
        $this->assertNotNull($outbox);
        $this->assertSame(BillingWebhookOutbox::StatusDelivered, $outbox->status);
        $this->assertSame(200, $outbox->last_http_status);
        $this->assertNotNull($outbox->delivered_at);
    }

    public function test_verified_expired_webhook_updates_payment_and_forwards_expired_status_to_billing(): void
    {
        [$merchant, $config] = $this->createMerchantWithConfig('production');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'amount_minor' => 150_000,
            'currency' => 'IDR',
            'status' => 'pending',
            'merchant_order_id' => 'INV-202611-0002-A1',
        ]);

        $forwardRequestCaptured = null;
        Http::fake([
            self::TEST_BILLING_WEBHOOK_URL => function (ClientRequest $request) use (&$forwardRequestCaptured) {
                $forwardRequestCaptured = $request;

                return Http::response(['status' => 'OK', 'message' => 'Expired processed'], 200);
            },
        ]);

        $rawBody = json_encode([
            'originalPartnerReferenceNo' => $payment->public_id,
            'originalReferenceNo' => 'DANA-REF-EXPIRED-999',
            'latestTransactionStatus' => '05',
            'merchantId' => '216880000000000000001',
            'amount' => ['value' => '150000.00', 'currency' => 'IDR'],
        ], JSON_THROW_ON_ERROR);

        $response = $this->postJson('/api/v1/webhooks/dana', json_decode($rawBody, true), [
            'X-TIMESTAMP' => now('Asia/Jakarta')->format('Y-m-d\TH:i:sP'),
            'X-PARTNER-ID' => 'test-partner-id',
            'X-SIGNATURE' => 'mocked-rsa-signature',
            'X-EXTERNAL-ID' => (string) $payment->public_id,
        ]);

        $response->assertOk();

        // 1. Payment lokal berubah menjadi expired
        $refreshed = $payment->fresh();
        $this->assertSame('expired', $refreshed->status);
        $this->assertNull($refreshed->paid_at);

        // 2. HTTP forwarding dikirim dengan status expired
        $this->assertNotNull($forwardRequestCaptured);
        $this->assertSame('expired', $forwardRequestCaptured['status']);
        $this->assertSame('05', $forwardRequestCaptured['latestTransactionStatus']);

        // 3. Outbox status delivered
        $outbox = BillingWebhookOutbox::query()->where('payment_id', $payment->id)->first();
        $this->assertNotNull($outbox);
        $this->assertSame(BillingWebhookOutbox::StatusDelivered, $outbox->status);
    }

    public function test_duplicate_webhook_is_deduplicated_and_does_not_forward_twice(): void
    {
        [$merchant, $config] = $this->createMerchantWithConfig('production');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'amount_minor' => 200_000,
            'currency' => 'IDR',
            'status' => 'pending',
            'merchant_order_id' => 'INV-202611-0003-A1',
        ]);

        $forwardCount = 0;
        Http::fake([
            self::TEST_BILLING_WEBHOOK_URL => function () use (&$forwardCount) {
                $forwardCount++;

                return Http::response(['status' => 'OK'], 200);
            },
        ]);

        $payload = [
            'originalPartnerReferenceNo' => $payment->public_id,
            'originalReferenceNo' => 'DANA-REF-DUP-1',
            'latestTransactionStatus' => '00',
            'merchantId' => '216880000000000000001',
            'amount' => ['value' => '200000.00', 'currency' => 'IDR'],
        ];

        $headers = [
            'X-TIMESTAMP' => now('Asia/Jakarta')->format('Y-m-d\TH:i:sP'),
            'X-PARTNER-ID' => 'test-partner-id',
            'X-SIGNATURE' => 'mocked-rsa-signature',
            'X-EXTERNAL-ID' => (string) $payment->public_id,
        ];

        // First call
        $this->postJson('/api/v1/webhooks/dana', $payload, $headers)->assertOk();
        $this->assertSame(1, $forwardCount);

        // Second duplicate call with same payload and signature
        $this->postJson('/api/v1/webhooks/dana', $payload, $headers)->assertOk();
        $this->assertSame(1, $forwardCount, 'Webhook duplikat seharusnya tidak memicu forwarding kedua kalinya.');
    }

    public function test_forwarding_network_failure_is_scheduled_for_retry_with_backoff(): void
    {
        [$merchant, $config] = $this->createMerchantWithConfig('production');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'amount_minor' => 75_000,
            'currency' => 'IDR',
            'status' => 'pending',
            'merchant_order_id' => 'INV-202611-0004-A1',
        ]);

        Http::fake([
            self::TEST_BILLING_WEBHOOK_URL => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $payload = [
            'originalPartnerReferenceNo' => $payment->public_id,
            'originalReferenceNo' => 'DANA-REF-FAIL-1',
            'latestTransactionStatus' => '00',
            'merchantId' => '216880000000000000001',
            'amount' => ['value' => '75000.00', 'currency' => 'IDR'],
        ];

        $this->postJson('/api/v1/webhooks/dana', $payload, [
            'X-TIMESTAMP' => now('Asia/Jakarta')->format('Y-m-d\TH:i:sP'),
            'X-PARTNER-ID' => 'test-partner-id',
            'X-SIGNATURE' => 'mocked-rsa-signature',
            'X-EXTERNAL-ID' => (string) $payment->public_id,
        ])->assertOk();

        // Status payment tetap paid lokal
        $this->assertSame('paid', $payment->fresh()->status);

        // Outbox harus berstatus pending dengan attempts = 1 dan available_at di masa depan
        $outbox = BillingWebhookOutbox::query()->where('payment_id', $payment->id)->first();
        $this->assertNotNull($outbox);
        $this->assertSame(BillingWebhookOutbox::StatusPending, $outbox->status);
        $this->assertSame(1, $outbox->attempts);
        $this->assertSame('network_timeout', $outbox->last_error);
        $this->assertTrue($outbox->available_at->isFuture());
    }

    public function test_forwarding_client_error_fails_permanently_without_retry(): void
    {
        [$merchant, $config] = $this->createMerchantWithConfig('production');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'amount_minor' => 80_000,
            'currency' => 'IDR',
            'status' => 'pending',
            'merchant_order_id' => 'INV-202611-0005-A1',
        ]);

        Http::fake([
            self::TEST_BILLING_WEBHOOK_URL => Http::response([
                'status' => 'error',
                'message' => 'Unauthorized token',
            ], 401),
        ]);

        $payload = [
            'originalPartnerReferenceNo' => $payment->public_id,
            'originalReferenceNo' => 'DANA-REF-401',
            'latestTransactionStatus' => '00',
            'merchantId' => '216880000000000000001',
            'amount' => ['value' => '80000.00', 'currency' => 'IDR'],
        ];

        $this->postJson('/api/v1/webhooks/dana', $payload, [
            'X-TIMESTAMP' => now('Asia/Jakarta')->format('Y-m-d\TH:i:sP'),
            'X-PARTNER-ID' => 'test-partner-id',
            'X-SIGNATURE' => 'mocked-rsa-signature',
            'X-EXTERNAL-ID' => (string) $payment->public_id,
        ])->assertOk();

        $outbox = BillingWebhookOutbox::query()->where('payment_id', $payment->id)->first();
        $this->assertNotNull($outbox);
        $this->assertSame(BillingWebhookOutbox::StatusFailed, $outbox->status);
        $this->assertSame(401, $outbox->last_http_status);
        $this->assertSame('billing_rejected_client_error', $outbox->last_error);
    }

    public function test_dispatch_pending_command_sweeps_and_delivers_pending_outboxes(): void
    {
        $merchant = Merchant::factory()->create();
        $payment = Payment::factory()->for($merchant)->create([
            'status' => 'paid',
        ]);

        $outbox = BillingWebhookOutbox::query()->create([
            'payment_id' => $payment->id,
            'environment' => 'production',
            'event_type' => 'payment.paid',
            'target_url' => self::TEST_BILLING_WEBHOOK_URL,
            'idempotency_key' => 'test-sweep-key-1',
            'payload' => [
                'merchant_order_id' => $payment->merchant_order_id,
                'id' => $payment->public_id,
                'status' => 'paid',
                'latestTransactionStatus' => '00',
                'amount_minor' => $payment->amount_minor,
            ],
            'status' => BillingWebhookOutbox::StatusPending,
            'attempts' => 1,
            'max_attempts' => 5,
            'available_at' => now()->subMinute(), // Due for retry
        ]);

        Http::fake([
            self::TEST_BILLING_WEBHOOK_URL => Http::response(['status' => 'OK'], 200),
        ]);

        $this->artisan('billing:dispatch-webhooks', ['--limit' => 10])
            ->assertSuccessful()
            ->expectsOutput('1 pesan outbox webhook berhasil diproses.');

        $this->assertSame(BillingWebhookOutbox::StatusDelivered, $outbox->fresh()->status);
        $this->assertSame(2, $outbox->fresh()->attempts);
    }

    /** @return array{0: Merchant, 1: MerchantPaymentConfig} */
    private function createMerchantWithConfig(string $environment): array
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $config = MerchantPaymentConfig::query()->create([
            'merchant_id' => $merchant->id,
            'provider' => 'dana',
            'environment' => $environment,
            'partner_id' => 'test-partner-id',
            'callback_url' => 'https://udev.udumbara.id/payment/result',
            'enabled' => true,
            'encrypted_credentials' => [
                'merchant_id' => '216880000000000000001',
                'private_key' => DanaSandboxKeyMaterialFixture::PrivateKey,
                'dana_webhook_public_key' => DanaSandboxKeyMaterialFixture::PublicKey,
                'origin' => 'https://udev.udumbara.id',
                'channel_id' => 'WEB',
                'notification_url' => 'https://dana.udumbara.id/api/v1/webhooks/dana',
                'mcc' => '5732',
                'external_store_id' => 'STORE-PROD-001',
            ],
        ]);

        return [$merchant, $config];
    }
}
