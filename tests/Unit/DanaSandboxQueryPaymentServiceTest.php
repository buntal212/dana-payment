<?php

namespace Tests\Unit;

use App\Enums\DanaSandboxPaymentMethod;
use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Services\DanaSandboxQueryPaymentService;
use App\Services\DanaSnapSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class DanaSandboxQueryPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private DanaSandboxQueryPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $signatureMock = $this->createMock(DanaSnapSignatureService::class);
        $signatureMock->method('signTransactional')->willReturn('mocked-snap-signature-12345');
        $this->app->instance(DanaSnapSignatureService::class, $signatureMock);

        $this->service = app(DanaSandboxQueryPaymentService::class);
    }

    public function test_resolve_base_url_defaults_to_saas_for_production_and_sandbox_for_sandbox(): void
    {
        $this->assertSame('https://api.saas.dana.id', $this->service->resolveBaseUrl('production'));
        $this->assertSame('https://api.sandbox.dana.id', $this->service->resolveBaseUrl('sandbox'));
        $this->assertSame('https://api.sandbox.dana.id', $this->service->resolveBaseUrl(null));
    }

    public function test_resolve_base_url_honors_custom_environment_configuration(): void
    {
        config()->set('services.dana.base_urls.production', 'https://custom-saas.dana.test');
        config()->set('services.dana.base_urls.sandbox', 'https://custom-sandbox.dana.test');

        $this->assertSame('https://custom-saas.dana.test', $this->service->resolveBaseUrl('production'));
        $this->assertSame('https://custom-sandbox.dana.test', $this->service->resolveBaseUrl('sandbox'));
    }

    public function test_query_production_payment_targets_saas_base_url_and_preserves_snap_headers_and_payload(): void
    {
        [$merchant, $config] = $this->createMerchantConfig('production');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'provider' => 'dana',
            'partner_reference_no' => 'PROD-REF-001',
            'public_id' => '01HZY000000000000000000001',
            'status' => 'pending',
        ]);

        $capturedRequest = null;
        Http::fake(function (HttpRequest $request) use (&$capturedRequest): array {
            $capturedRequest = $request;

            return [
                'responseCode' => '2005500',
                'responseMessage' => 'Successful',
                'latestTransactionStatus' => '01',
                'requestID' => 'dana-req-prod-001',
            ];
        });

        $result = $this->service->query($payment, $config);

        $this->assertNotNull($capturedRequest);
        $this->assertSame('POST', $capturedRequest->method());
        $this->assertSame('https://api.saas.dana.id/payment-gateway/v1.0/debit/status.htm', $capturedRequest->url());

        // Header check
        $this->assertSame('mocked-snap-signature-12345', $capturedRequest->header('X-SIGNATURE')[0]);
        $this->assertSame('partner-production-001', $capturedRequest->header('X-PARTNER-ID')[0]);
        $this->assertSame('95221', $capturedRequest->header('CHANNEL-ID')[0]);
        $this->assertSame('merchant.example.com', $capturedRequest->header('ORIGIN')[0]);
        $this->assertNotEmpty($capturedRequest->header('X-TIMESTAMP')[0]);
        $this->assertStringStartsWith('status-', $capturedRequest->header('X-EXTERNAL-ID')[0]);

        // Payload check
        $data = $capturedRequest->data();
        $this->assertSame('PROD-REF-001', $data['originalPartnerReferenceNo']);
        $this->assertSame('54', $data['serviceCode']);
        $this->assertSame('merchant-production-001', $data['merchantId']);

        // Result check
        $this->assertSame(200, $result['http_status']);
        $this->assertSame('2005500', $result['response_code']);
        $this->assertSame('Successful', $result['response_message']);
        $this->assertSame('01', $result['latest_transaction_status']);
        $this->assertSame('dana-req-prod-001', $result['request_id']);
        $this->assertNotNull($result['checked_at']);
    }

    public function test_query_sandbox_payment_targets_sandbox_base_url_and_returns_status(): void
    {
        [$merchant, $config] = $this->createMerchantConfig('sandbox');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'sandbox',
            'provider' => 'dana',
            'partner_reference_no' => 'SBX-REF-001',
            'public_id' => '01HZY000000000000000000002',
            'status' => 'pending',
        ]);

        $capturedRequest = null;
        Http::fake(function (HttpRequest $request) use (&$capturedRequest): array {
            $capturedRequest = $request;

            return [
                'responseCode' => '2005500',
                'responseMessage' => 'Successful',
                'latestTransactionStatus' => '01',
                'requestID' => 'dana-req-sbx-001',
            ];
        });

        $result = $this->service->query($payment, $config);

        $this->assertNotNull($capturedRequest);
        $this->assertSame('https://api.sandbox.dana.id/payment-gateway/v1.0/debit/status.htm', $capturedRequest->url());
        $this->assertSame('2005500', $result['response_code']);
        $this->assertSame('01', $result['latest_transaction_status']);
        $this->assertSame('dana-req-sbx-001', $result['request_id']);
    }

    public function test_query_rejects_non_dana_provider(): void
    {
        [$merchant, $config] = $this->createMerchantConfig('production');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'provider' => 'midtrans',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Query Payment hanya tersedia untuk pembayaran DANA.');

        $this->service->query($payment, $config);
    }

    public function test_query_rejects_unsupported_environment(): void
    {
        [$merchant, $config] = $this->createMerchantConfig('production');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'staging',
            'provider' => 'dana',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Query Payment tidak tersedia untuk environment DANA Staging.');

        $this->service->query($payment, $config);
    }

    public function test_query_rejects_mismatched_config_environment(): void
    {
        [$merchant, $config] = $this->createMerchantConfig('sandbox');
        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'provider' => 'dana',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Query Payment memerlukan konfigurasi DANA Production.');

        $this->service->query($payment, $config);
    }

    public function test_query_connection_exception_error_message_matches_environment(): void
    {
        // 1. Production Connection Error
        [$merchantProd, $configProd] = $this->createMerchantConfig('production');
        $paymentProd = Payment::factory()->for($merchantProd)->create([
            'environment' => 'production',
            'provider' => 'dana',
        ]);

        Http::fake(function (): void {
            throw new ConnectionException('Connection timeout');
        });

        try {
            $this->service->query($paymentProd, $configProd);
            $this->fail('Harus melempar RuntimeException untuk kegagalan koneksi Production.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Query Payment DANA Production sementara tidak tersedia.', $exception->getMessage());
        }

        // 2. Sandbox Connection Error
        [$merchantSbx, $configSbx] = $this->createMerchantConfig('sandbox');
        $paymentSbx = Payment::factory()->for($merchantSbx)->create([
            'environment' => 'sandbox',
            'provider' => 'dana',
        ]);

        try {
            $this->service->query($paymentSbx, $configSbx);
            $this->fail('Harus melempar RuntimeException untuk kegagalan koneksi Sandbox.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Query Payment DANA Sandbox sementara tidak tersedia.', $exception->getMessage());
        }
    }

    public function test_query_qris_without_external_store_id_error_message_is_environment_aware(): void
    {
        // Production QRIS without External Store ID
        [$merchantProd, $configProd] = $this->createMerchantConfig('production', hasExternalStoreId: false);
        $paymentProd = Payment::factory()->for($merchantProd)->create([
            'environment' => 'production',
            'provider' => 'dana',
            'metadata' => ['udev_sandbox_payment_method' => DanaSandboxPaymentMethod::Qris->value],
        ]);

        try {
            $this->service->query($paymentProd, $configProd);
            $this->fail('Harus melempar LogicException untuk QRIS Production tanpa external store ID.');
        } catch (LogicException $exception) {
            $this->assertSame('QRIS Production memerlukan External Store ID pada konfigurasi merchant.', $exception->getMessage());
        }

        // Sandbox QRIS without External Store ID
        [$merchantSbx, $configSbx] = $this->createMerchantConfig('sandbox', hasExternalStoreId: false);
        $paymentSbx = Payment::factory()->for($merchantSbx)->create([
            'environment' => 'sandbox',
            'provider' => 'dana',
            'metadata' => ['udev_sandbox_payment_method' => DanaSandboxPaymentMethod::Qris->value],
        ]);

        try {
            $this->service->query($paymentSbx, $configSbx);
            $this->fail('Harus melempar LogicException untuk QRIS Sandbox tanpa external store ID.');
        } catch (LogicException $exception) {
            $this->assertSame('QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.', $exception->getMessage());
        }
    }

    public function test_sandbox_uat_functions_remain_isolated_to_sandbox(): void
    {
        [$merchantProd, $configProd] = $this->createMerchantConfig('production');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Query Payment hanya tersedia untuk konfigurasi DANA Sandbox.');
        $this->service->queryFictitiousReference('fake-ref-123', $configProd);
    }

    /** @return array{Merchant, MerchantPaymentConfig} */
    private function createMerchantConfig(string $environment, bool $hasExternalStoreId = true): array
    {
        $merchant = Merchant::factory()->create(['name' => "Merchant {$environment}", 'code' => "merchant-{$environment}"]);
        $credentials = [
            'merchant_id' => "merchant-{$environment}-001",
            'private_key' => 'test-private-key-rsa',
            'dana_webhook_public_key' => 'test-public-key',
            'origin' => 'merchant.example.com',
            'channel_id' => '95221',
            'notification_url' => 'https://merchant.example.com/webhook',
            'mcc' => '5814',
        ];
        if ($hasExternalStoreId) {
            $credentials['external_store_id'] = "store-{$environment}-001";
        }

        $config = MerchantPaymentConfig::factory()->for($merchant)->create([
            'environment' => $environment,
            'provider' => 'dana',
            'partner_id' => "partner-{$environment}-001",
            'enabled' => true,
            'encrypted_credentials' => $credentials,
        ]);

        return [$merchant, $config];
    }
}
