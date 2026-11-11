<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Services\DanaPaymentGateway;
use App\Services\DanaSnapSignatureService;
use App\Services\MerchantApiKeyService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DanaEnvironmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $signatureMock = $this->createMock(DanaSnapSignatureService::class);
        $signatureMock->method('signTransactional')->willReturn('mocked-snap-signature');
        $this->app->instance(DanaSnapSignatureService::class, $signatureMock);
    }

    public function test_resolve_base_url_isolates_sandbox_and_production_endpoints(): void
    {
        $gateway = app(DanaPaymentGateway::class);

        $this->assertSame('https://api.sandbox.dana.id', $gateway->resolveBaseUrl('sandbox'));
        $this->assertSame('https://api.dana.id', $gateway->resolveBaseUrl('production'));
        $this->assertSame('https://api.sandbox.dana.id', $gateway->resolveBaseUrl(null));
        $this->assertSame('https://api.sandbox.dana.id', $gateway->resolveBaseUrl('unknown_env'));
    }

    public function test_resolve_base_url_honors_explicit_environment_configuration(): void
    {
        config()->set('services.dana.base_urls.sandbox', 'https://custom-sandbox.dana.test');
        config()->set('services.dana.base_urls.production', 'https://custom-production.dana.test');

        $gateway = app(DanaPaymentGateway::class);

        $this->assertSame('https://custom-sandbox.dana.test', $gateway->resolveBaseUrl('sandbox'));
        $this->assertSame('https://custom-production.dana.test', $gateway->resolveBaseUrl('production'));
    }

    public function test_sandbox_checkout_dispatches_create_order_to_sandbox_base_url(): void
    {
        [$merchant, $config] = $this->createMerchantWithConfig('sandbox');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'sandbox',
            'amount_minor' => 50_000,
            'currency' => 'IDR',
        ]);

        $targetedUrl = null;
        Http::fake(function (HttpRequest $request) use (&$targetedUrl): array {
            $targetedUrl = $request->url();

            return [
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-ORDER-SANDBOX-TEST',
                'webRedirectUrl' => 'https://checkout.sandbox.dana.id/session/sandbox-test',
            ];
        });

        $result = app(DanaPaymentGateway::class)->startCheckout($payment, $config);

        $this->assertSame('https://api.sandbox.dana.id/payment-gateway/v1.0/debit/payment-host-to-host.htm', $targetedUrl);
        $this->assertSame('DANA-ORDER-SANDBOX-TEST', $result['provider_order_id']);
        $this->assertSame('https://checkout.sandbox.dana.id/session/sandbox-test', $result['checkout_url']);
    }

    public function test_production_checkout_dispatches_create_order_to_production_base_url(): void
    {
        [$merchant, $config] = $this->createMerchantWithConfig('production');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'amount_minor' => 75_000,
            'currency' => 'IDR',
        ]);

        $targetedUrl = null;
        Http::fake(function (HttpRequest $request) use (&$targetedUrl): array {
            $targetedUrl = $request->url();

            return [
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-ORDER-PROD-TEST',
                'webRedirectUrl' => 'https://checkout.dana.id/session/prod-test',
            ];
        });

        $result = app(DanaPaymentGateway::class)->startCheckout($payment, $config);

        $this->assertSame('https://api.dana.id/payment-gateway/v1.0/debit/payment-host-to-host.htm', $targetedUrl);
        $this->assertSame('DANA-ORDER-PROD-TEST', $result['provider_order_id']);
        $this->assertSame('https://checkout.dana.id/session/prod-test', $result['checkout_url']);
    }

    public function test_payment_service_uses_specified_environment_and_selects_corresponding_rsa_key_and_endpoint(): void
    {
        $capturedPrivateKey = null;
        $signatureMock = $this->createMock(DanaSnapSignatureService::class);
        $signatureMock->method('signTransactional')->willReturnCallback(function ($method, $path, $body, $timestamp, $privateKey) use (&$capturedPrivateKey) {
            $capturedPrivateKey = $privateKey;

            return 'mocked-snap-signature';
        });
        $this->app->instance(DanaSnapSignatureService::class, $signatureMock);

        $merchant = Merchant::factory()->create(['name' => 'Dual Env Merchant', 'code' => 'dual-env-merchant']);

        // Sandbox config with specific Sandbox RSA Key
        MerchantPaymentConfig::factory()->for($merchant)->create([
            'environment' => 'sandbox',
            'partner_id' => 'partner-sbx',
            'callback_url' => 'https://merchant.example/return',
            'encrypted_credentials' => [
                'merchant_id' => 'merchant-sbx',
                'client_secret' => 'secret-sbx',
                'channel_id' => '95221',
                'mcc' => '5411',
                'origin' => 'merchant.example',
                'notification_url' => 'https://merchant.example/webhook/dana',
                'return_url' => 'https://merchant.example/return',
                'private_key' => 'rsa-private-key-sandbox-12345',
                'dana_webhook_public_key' => 'public-key-sbx',
            ],
            'enabled' => true,
        ]);

        // Production config with specific Production RSA Key
        MerchantPaymentConfig::factory()->for($merchant)->create([
            'environment' => 'production',
            'partner_id' => 'partner-prod',
            'callback_url' => 'https://merchant.example/return',
            'encrypted_credentials' => [
                'merchant_id' => 'merchant-prod',
                'client_secret' => 'secret-prod',
                'channel_id' => '95221',
                'mcc' => '5411',
                'origin' => 'merchant.example',
                'notification_url' => 'https://merchant.example/webhook/dana',
                'return_url' => 'https://merchant.example/return',
                'private_key' => 'rsa-private-key-production-67890',
                'dana_webhook_public_key' => 'public-key-prod',
            ],
            'enabled' => true,
        ]);

        $targetedUrls = [];
        Http::fake(function (HttpRequest $request) use (&$targetedUrls): array {
            $targetedUrls[] = $request->url();

            return [
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-ORDER-REF',
                'webRedirectUrl' => 'https://checkout.dana.id/session/test',
            ];
        });

        $paymentService = app(PaymentService::class);
        $gateway = app(DanaPaymentGateway::class);

        // 1. Production Checkout
        $prodPayment = $paymentService->createHostedCheckout($merchant, [
            'merchant_order_id' => 'ORDER-PROD-001',
            'amount_minor' => 100_000,
            'currency' => 'IDR',
            'idempotency_key' => 'idemp-prod-001',
            'environment' => 'production',
        ], $gateway);

        $this->assertSame('production', $prodPayment->environment);
        $this->assertSame('rsa-private-key-production-67890', $capturedPrivateKey);
        $this->assertSame('https://api.dana.id/payment-gateway/v1.0/debit/payment-host-to-host.htm', end($targetedUrls));

        // 2. Sandbox Checkout
        $sbxPayment = $paymentService->createHostedCheckout($merchant, [
            'merchant_order_id' => 'ORDER-SBX-001',
            'amount_minor' => 50_000,
            'currency' => 'IDR',
            'idempotency_key' => 'idemp-sbx-001',
            'environment' => 'sandbox',
        ], $gateway);

        $this->assertSame('sandbox', $sbxPayment->environment);
        $this->assertSame('rsa-private-key-sandbox-12345', $capturedPrivateKey);
        $this->assertSame('https://api.sandbox.dana.id/payment-gateway/v1.0/debit/payment-host-to-host.htm', end($targetedUrls));
    }

    public function test_production_checkout_rejects_silent_fallback_when_production_config_missing(): void
    {
        $merchant = Merchant::factory()->create(['name' => 'Sandbox Only Merchant', 'code' => 'sandbox-only-merchant']);

        // Merchant only has sandbox config
        MerchantPaymentConfig::factory()->for($merchant)->create([
            'environment' => 'sandbox',
            'partner_id' => 'partner-sbx',
            'callback_url' => 'https://merchant.example/return',
            'encrypted_credentials' => [
                'merchant_id' => 'merchant-sbx',
                'client_secret' => 'secret-sbx',
                'channel_id' => '95221',
                'mcc' => '5411',
                'origin' => 'merchant.example',
                'notification_url' => 'https://merchant.example/webhook/dana',
                'return_url' => 'https://merchant.example/return',
                'private_key' => 'rsa-private-key-sandbox',
                'dana_webhook_public_key' => 'public-key-sbx',
            ],
            'enabled' => true,
        ]);

        $paymentService = app(PaymentService::class);
        $gateway = app(DanaPaymentGateway::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Konfigurasi DANA Production belum tersedia untuk merchant ini.');

        $paymentService->createHostedCheckout($merchant, [
            'merchant_order_id' => 'ORDER-PROD-FAIL',
            'amount_minor' => 100_000,
            'currency' => 'IDR',
            'idempotency_key' => 'idemp-prod-fail',
            'environment' => 'production',
        ], $gateway);
    }

    public function test_api_v1_payments_validates_environment_and_rejects_invalid_values(): void
    {
        $merchant = Merchant::factory()->create();
        $apiKeyData = app(MerchantApiKeyService::class)->create($merchant, 'test-key');

        // 1. Invalid environment string is rejected by StorePaymentRequest
        $response = $this->withToken($apiKeyData['api_key'])->postJson('/api/v1/payments', [
            'merchant_order_id' => 'ORD-INVALID-ENV',
            'amount_minor' => 10_000,
            'currency' => 'IDR',
            'environment' => 'staging',
        ], ['Idempotency-Key' => 'idemp-inv-env']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['environment']);

        // 2. Production requested but config not available -> 422 error, no fallback
        $responseNoProdConfig = $this->withToken($apiKeyData['api_key'])->postJson('/api/v1/payments', [
            'merchant_order_id' => 'ORD-PROD-NO-CONFIG',
            'amount_minor' => 10_000,
            'currency' => 'IDR',
            'environment' => 'production',
        ], ['Idempotency-Key' => 'idemp-prod-no-cfg']);

        $responseNoProdConfig->assertStatus(422)
            ->assertJsonPath('message', 'Konfigurasi checkout merchant belum lengkap.');
    }

    /**
     * @return array{0: Merchant, 1: MerchantPaymentConfig}
     */
    private function createMerchantWithConfig(string $environment): array
    {
        $merchant = Merchant::factory()->create([
            'name' => "Merchant {$environment}",
            'code' => "merchant-{$environment}",
        ]);

        $config = MerchantPaymentConfig::factory()->for($merchant)->create([
            'environment' => $environment,
            'partner_id' => "partner-{$environment}-001",
            'callback_url' => 'https://merchant.example/payment-return',
            'encrypted_credentials' => [
                'merchant_id' => "merchant-{$environment}-001",
                'client_secret' => 'secret-test',
                'channel_id' => '95221',
                'mcc' => '5411',
                'origin' => 'merchant.example',
                'notification_url' => 'https://merchant.example/webhook/dana',
                'return_url' => 'https://merchant.example/payment-return',
                'private_key' => 'test-private-key',
                'dana_webhook_public_key' => 'test-public-key',
            ],
            'enabled' => true,
        ]);

        return [$merchant, $config];
    }
}
