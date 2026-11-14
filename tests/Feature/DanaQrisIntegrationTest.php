<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Services\DanaPaymentGateway;
use App\Services\DanaSnapSignatureService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\DanaSandboxKeyMaterialFixture;
use Tests\TestCase;

class DanaQrisIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const string EmvcoQrisSample = '00020101021226570011ID.DANA.WWW011893600915000295707502090029570750303UMI51380014ID.CO.QRIS.WWW02090029570750303UMI5204737253033605405100005802ID5905VZPAY6015Kota Jakarta Se61051224062710119anjartyrtyrtyy1234560440011ID.DANA.WWW0425MER202609041029168370062863046D16';

    protected function setUp(): void
    {
        parent::setUp();

        $signatureMock = $this->createMock(DanaSnapSignatureService::class);
        $signatureMock->method('signTransactional')->willReturn('mocked-snap-signature');
        $this->app->instance(DanaSnapSignatureService::class, $signatureMock);
    }

    public function test_qris_production_creates_order_without_sandbox_label_and_with_valid_pay_option(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'MRC-QRIS-PROD']);
        $config = $this->createMerchantConfig($merchant, 'production', 'PROD-STORE-001');

        $paymentService = app(PaymentService::class);
        $gateway = app(DanaPaymentGateway::class);

        $orderId = 'PROD-ORDER-'.time();
        $idempotencyKey = 'idemp-qris-prod-001';

        $capturedRequest = null;
        Http::fake(function (HttpRequest $request) use (&$capturedRequest): array {
            $capturedRequest = $request;

            return [
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-PROD-REF-001',
                'additionalInfo' => [
                    'qrContent' => self::EmvcoQrisSample,
                ],
            ];
        });

        $payment = $paymentService->createHostedCheckout($merchant, [
            'merchant_order_id' => $orderId,
            'amount_minor' => 150_000,
            'currency' => 'IDR',
            'idempotency_key' => $idempotencyKey,
            'environment' => 'production',
            'metadata' => [
                'payment_method' => 'qris',
            ],
        ], $gateway);

        $this->assertNotNull($capturedRequest);
        $this->assertSame('https://api.dana.id/payment-gateway/v1.0/debit/payment-host-to-host.htm', $capturedRequest->url());

        $body = $capturedRequest->data();
        $this->assertSame('PROD-STORE-001', $body['externalStoreId']);
        $this->assertIsArray($body['payOptionDetails']);
        $this->assertSame('NETWORK_PAY', $body['payOptionDetails'][0]['payMethod']);
        $this->assertSame('NETWORK_PAY_PG_QRIS', $body['payOptionDetails'][0]['payOption']);
        $this->assertSame('150000.00', $body['payOptionDetails'][0]['transAmount']['value']);

        // Verify orderTitle does NOT contain Sandbox label in Production
        $orderTitle = $body['additionalInfo']['order']['orderTitle'];
        $this->assertSame('Payment '.$orderId, $orderTitle);
        $this->assertStringNotContainsString('Sandbox', $orderTitle);
        $this->assertSame('API', $body['additionalInfo']['order']['scenario']);

        // Verify partnerReferenceNo length is at most 25 characters and matches Q + 24 sha256 hex
        $partnerRef = $body['partnerReferenceNo'];
        $this->assertLessThanOrEqual(25, strlen($partnerRef));
        $this->assertSame(25, strlen($partnerRef));
        $this->assertMatchesRegularExpression('/\AQ[A-Fa-f0-9]{24}\z/', $partnerRef);
        $this->assertSame($payment->partner_reference_no, $partnerRef);

        // Verify payment is saved with qris_mpm instruction and legacy metadata compatibility
        $this->assertSame('qris_mpm', $payment->metadata['payment_instruction']['type']);
        $this->assertSame(self::EmvcoQrisSample, $payment->metadata['payment_instruction']['qr_content']);
        $this->assertSame('qris_mpm', $payment->metadata['udev_sandbox_payment_instruction']['type']);
        $this->assertSame(self::EmvcoQrisSample, $payment->metadata['udev_sandbox_payment_instruction']['qr_content']);
    }

    public function test_qris_sandbox_creates_order_with_sandbox_label_and_legacy_metadata_compatibility(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'MRC-QRIS-SBX']);
        $config = $this->createMerchantConfig($merchant, 'sandbox', 'SBX-STORE-001');

        $paymentService = app(PaymentService::class);
        $gateway = app(DanaPaymentGateway::class);

        $orderId = 'SBX-ORDER-'.time();
        $idempotencyKey = 'idemp-qris-sbx-001';

        $capturedRequest = null;
        Http::fake(function (HttpRequest $request) use (&$capturedRequest): array {
            $capturedRequest = $request;

            return [
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-SBX-REF-001',
                'additionalInfo' => [
                    'paymentCode' => 'RAW-PAYMENT-CODE-12345',
                ],
            ];
        });

        // Test using legacy metadata udev_sandbox_payment_method
        $payment = $paymentService->createHostedCheckout($merchant, [
            'merchant_order_id' => $orderId,
            'amount_minor' => 25_000,
            'currency' => 'IDR',
            'idempotency_key' => $idempotencyKey,
            'environment' => 'sandbox',
            'metadata' => [
                'udev_sandbox_payment_method' => 'qris',
            ],
        ], $gateway);

        $this->assertNotNull($capturedRequest);
        $this->assertSame('https://api.sandbox.dana.id/payment-gateway/v1.0/debit/payment-host-to-host.htm', $capturedRequest->url());

        $body = $capturedRequest->data();
        $this->assertSame('SBX-STORE-001', $body['externalStoreId']);
        $this->assertSame('NETWORK_PAY', $body['payOptionDetails'][0]['payMethod']);
        $this->assertSame('NETWORK_PAY_PG_QRIS', $body['payOptionDetails'][0]['payOption']);

        // Verify orderTitle contains Sandbox label in Sandbox
        $orderTitle = $body['additionalInfo']['order']['orderTitle'];
        $this->assertSame('Sandbox '.$orderId, $orderTitle);

        // Verify partnerReferenceNo length is at most 25 characters
        $partnerRef = $body['partnerReferenceNo'];
        $this->assertSame(25, strlen($partnerRef));
        $this->assertMatchesRegularExpression('/\AQ[A-Fa-f0-9]{24}\z/', $partnerRef);

        // Verify non-emvco fallback type is payment_code
        $this->assertSame('payment_code', $payment->metadata['payment_instruction']['type']);
        $this->assertSame('RAW-PAYMENT-CODE-12345', $payment->metadata['payment_instruction']['payment_code']);
        $this->assertSame('payment_code', $payment->metadata['udev_sandbox_payment_instruction']['type']);
        $this->assertSame('RAW-PAYMENT-CODE-12345', $payment->metadata['udev_sandbox_payment_instruction']['payment_code']);
    }

    public function test_qris_checkout_validates_environment_match_between_payment_and_config(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'MRC-ENV-MISMATCH']);
        $sandboxConfig = $this->createMerchantConfig($merchant, 'sandbox', 'STORE-SBX');
        $prodConfig = $this->createMerchantConfig($merchant, 'production', 'STORE-PROD');

        $gateway = app(DanaPaymentGateway::class);

        // Payment is Production, but Sandbox Config is passed
        $prodPayment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'partner_reference_no' => 'Q'.str_repeat('1', 24),
            'metadata' => ['payment_method' => 'qris'],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Environment Payment (Production) tidak cocok dengan konfigurasi DANA merchant (Sandbox).');
        $gateway->startCheckout($prodPayment, $sandboxConfig);
    }

    public function test_qris_checkout_validates_reverse_environment_mismatch(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'MRC-ENV-REV-MISMATCH']);
        $prodConfig = $this->createMerchantConfig($merchant, 'production', 'STORE-PROD');

        $gateway = app(DanaPaymentGateway::class);

        // Payment is Sandbox, but Production Config is passed
        $sbxPayment = Payment::factory()->for($merchant)->create([
            'environment' => 'sandbox',
            'partner_reference_no' => 'Q'.str_repeat('2', 24),
            'metadata' => ['payment_method' => 'qris'],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Environment Payment (Sandbox) tidak cocok dengan konfigurasi DANA merchant (Production).');
        $gateway->startCheckout($sbxPayment, $prodConfig);
    }

    public function test_qris_partner_reference_no_is_idempotent_and_max_25_chars_for_production(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'MRC-IDEMP-PROD']);
        $attributes = [
            'merchant_order_id' => 'qris-prod-idemp-001',
            'amount_minor' => 100_000,
            'currency' => 'IDR',
            'idempotency_key' => 'idemp-key-qris-prod-001',
            'environment' => 'production',
            'metadata' => [
                'payment_method' => 'qris',
            ],
        ];

        $paymentService = app(PaymentService::class);
        $first = $paymentService->createPending($merchant, $attributes);
        $second = $paymentService->createPending($merchant, $attributes);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->partner_reference_no, $second->partner_reference_no);
        $this->assertSame(25, strlen((string) $first->partner_reference_no));
        $this->assertMatchesRegularExpression('/\AQ[A-Fa-f0-9]{24}\z/', (string) $first->partner_reference_no);
    }

    public function test_qris_production_missing_external_store_id_throws_production_specific_error(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'MRC-NO-STORE-PROD']);
        $config = $this->createMerchantConfig($merchant, 'production', null);

        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'production',
            'partner_reference_no' => 'Q'.str_repeat('3', 24),
            'metadata' => ['payment_method' => 'qris'],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('QRIS Production memerlukan External Store ID pada konfigurasi merchant.');

        app(DanaPaymentGateway::class)->startCheckout($payment, $config);
    }

    public function test_qris_sandbox_missing_external_store_id_throws_sandbox_specific_error(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'MRC-NO-STORE-SBX']);
        $config = $this->createMerchantConfig($merchant, 'sandbox', null);

        $payment = Payment::factory()->for($merchant)->create([
            'environment' => 'sandbox',
            'partner_reference_no' => 'Q'.str_repeat('4', 24),
            'metadata' => ['udev_sandbox_payment_method' => 'qris'],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.');

        app(DanaPaymentGateway::class)->startCheckout($payment, $config);
    }

    private function createMerchantConfig(Merchant $merchant, string $environment, ?string $externalStoreId): MerchantPaymentConfig
    {
        $credentials = [
            'merchant_id' => 'MERCHANT-'.$environment.'-001',
            'channel_id' => '95221',
            'mcc' => '5411',
            'origin' => 'merchant.example',
            'notification_url' => 'https://merchant.example/webhook/dana',
            'return_url' => 'https://merchant.example/return',
            'private_key' => DanaSandboxKeyMaterialFixture::PrivateKey,
            'dana_webhook_public_key' => DanaSandboxKeyMaterialFixture::PublicKey,
        ];

        if ($externalStoreId !== null) {
            $credentials['external_store_id'] = $externalStoreId;
        }

        return MerchantPaymentConfig::factory()->for($merchant)->create([
            'environment' => $environment,
            'partner_id' => 'PARTNER-'.$environment.'-001',
            'callback_url' => 'https://merchant.example/return',
            'encrypted_credentials' => $credentials,
            'enabled' => true,
        ]);
    }
}
