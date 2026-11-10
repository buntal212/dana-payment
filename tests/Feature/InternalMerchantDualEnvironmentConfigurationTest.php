<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantPaymentConfig;
use Tests\Fixtures\DanaSandboxKeyMaterialFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class InternalMerchantDualEnvironmentConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const string ServiceToken = 'test-internal-token';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://gateway.test');
        $this->app['url']->forceScheme('https');

        config()->set('services.udev_internal', [
            'enabled' => true,
            'service_id' => 'udev-billing',
            'token' => self::ServiceToken,
            'abilities' => [
                'merchants:read',
                'merchants:write',
                'merchants:readiness',
                'merchants:credentials:read',
                'merchants:credentials:write',
            ],
        ]);
    }

    public function test_single_merchant_supports_dual_environment_configuration_and_independent_readiness(): void
    {
        // 1. Provision single merchant without specifying environment (defaults to sandbox)
        $provisionResp = $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', [
                'code' => 'MRC-DUAL-001',
                'name' => 'Toko Dual Environment',
                'email' => 'contact@dual.test',
                'idempotency_key' => 'idemp-dual-001',
            ])
            ->assertStatus(Response::HTTP_CREATED);

        $merchantId = $provisionResp->json('data.merchant_id');
        $this->assertNotNull($merchantId);

        // Ensure exactly 1 merchant entity was created
        $this->assertSame(1, Merchant::query()->where('code', 'MRC-DUAL-001')->count());

        $merchant = Merchant::query()->findOrFail($merchantId);
        // Create an active API key so that API key check passes for both
        MerchantApiKey::factory()->create([
            'merchant_id' => $merchant->id,
            'is_active' => true,
            'revoked_at' => null,
        ]);

        $rsaKeys = [
            'private' => DanaSandboxKeyMaterialFixture::PrivateKey,
            'public' => DanaSandboxKeyMaterialFixture::PublicKey,
        ];

        // 2. Configure DANA Sandbox on merchant
        $sandboxPayload = [
            'environment' => 'sandbox',
            'idempotency_key' => 'conf-sandbox-001',
            'partner_id' => '200000000001',
            'merchant_id' => '216620000000000000001',
            'origin' => 'https://sandbox.dual.test',
            'channel_id' => 'DANA_ONLINE',
            'notification_url' => 'https://sandbox.dual.test/notify',
            'callback_url' => 'https://sandbox.dual.test/finish',
            'mcc' => '5411',
            'enabled' => true,
            'private_key' => $rsaKeys['private'],
            'dana_webhook_public_key' => $rsaKeys['public'],
        ];

        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", $sandboxPayload)
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.dana_configuration.ready', true);

        // 3. Query Sandbox readiness: ready!
        $this->withToken(self::ServiceToken)
            ->getJson("/api/v1/internal/merchants/{$merchantId}/readiness?environment=sandbox")
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.readiness_status', 'ready');

        // 4. Query Production readiness before configuration: not ready!
        $this->withToken(self::ServiceToken)
            ->getJson("/api/v1/internal/merchants/{$merchantId}/readiness?environment=production")
            ->assertOk()
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.readiness_status', 'not_ready');

        // 5. Configure DANA Production on THE SAME merchant
        $productionPayload = [
            'environment' => 'production',
            'idempotency_key' => 'conf-production-001',
            'partner_id' => '200000000002',
            'merchant_id' => '216620000000000000002',
            'origin' => 'https://live.dual.test',
            'channel_id' => 'DANA_ONLINE_PROD',
            'notification_url' => 'https://live.dual.test/notify',
            'callback_url' => 'https://live.dual.test/finish',
            'mcc' => '5411',
            'enabled' => true,
            'private_key' => $rsaKeys['private'],
            'dana_webhook_public_key' => $rsaKeys['public'],
        ];

        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", $productionPayload)
            ->assertOk()
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.dana_configuration.ready', true);

        // 6. Query Production readiness now: ready!
        $this->withToken(self::ServiceToken)
            ->getJson("/api/v1/internal/merchants/{$merchantId}/readiness?environment=production")
            ->assertOk()
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.readiness_status', 'ready');

        // 7. Verify both environments exist on the single merchant entity
        $this->assertSame(1, Merchant::query()->where('code', 'MRC-DUAL-001')->count());
        $configs = MerchantPaymentConfig::query()->where('merchant_id', $merchantId)->get();
        $this->assertCount(2, $configs);
        $this->assertTrue($configs->contains('environment', 'sandbox'));
        $this->assertTrue($configs->contains('environment', 'production'));
    }
}
