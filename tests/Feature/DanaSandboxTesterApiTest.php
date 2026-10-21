<?php

namespace Tests\Feature;

use App\Models\DanaSandboxTestRun;
use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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

    public function test_creates_sandbox_checkout_through_existing_gateway_and_never_marks_it_paid(): void
    {
        $merchant = $this->managedMerchant();
        $this->validConfiguration($merchant);
        Http::preventStrayRequests();
        Http::fake([
            'https://dana-sandbox.test/*' => Http::response([
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-SANDBOX-ORDER-001',
                'webRedirectUrl' => 'https://checkout.sandbox.dana.id/session/tester-001',
            ]),
        ]);

        $response = $this->withToken(self::ServiceToken)
            ->postJson($this->transactionUrl($merchant), ['amount_minor' => 10_000], ['Idempotency-Key' => 'sandbox-tester-transaction-001'])
            ->assertCreated()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.authentication.status', 'confirmed_by_checkout_response')
            ->assertJsonPath('data.transaction.status', 'pending')
            ->assertJsonPath('data.transaction.checkout_available', true)
            ->assertJsonPath('data.transaction.checkout_url', 'https://checkout.sandbox.dana.id/session/tester-001');

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
            return $request->url() === 'https://dana-sandbox.test/payment-gateway/v1.0/debit/payment-host-to-host.htm'
                && $request->hasHeader('X-PARTNER-ID', 'partner-sandbox-001')
                && $request->hasHeader('CHANNEL-ID', 'channel-sandbox-001')
                && ! str_contains($request->body(), DanaSandboxKeyMaterialFixture::PrivateKey);
        });
    }

    public function test_status_and_history_are_isolated_to_sandbox_tester_payments_of_the_managed_merchant(): void
    {
        $merchant = $this->managedMerchant(['code' => 'udev-one']);
        $otherMerchant = $this->managedMerchant(['code' => 'udev-two']);
        $ownPayment = Payment::factory()->for($merchant)->create([
            'status' => 'pending',
            'metadata' => ['udev_sandbox_tester' => true],
        ]);
        $otherPayment = Payment::factory()->for($otherMerchant)->create([
            'status' => 'pending',
            'metadata' => ['udev_sandbox_tester' => true],
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

    private function transactionStatusUrl(Merchant $merchant, Payment $payment): string
    {
        return $this->transactionUrl($merchant)."/{$payment->public_id}/status";
    }
}
