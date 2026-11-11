<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Services\DanaPaymentGateway;
use App\Services\DanaSnapSignatureService;
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
