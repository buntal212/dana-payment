<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantPaymentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\DanaSandboxKeyMaterialFixture;
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

    public function test_production_configuration_stores_client_id_and_encrypted_client_secret_safely(): void
    {
        $provisionResp = $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', [
                'code' => 'MRC-PROD-SEC',
                'name' => 'Toko Production Secret Test',
                'email' => 'prod@test.com',
                'idempotency_key' => 'idemp-prod-sec-001',
            ])
            ->assertStatus(Response::HTTP_CREATED);

        $merchantId = $provisionResp->json('data.merchant_id');

        $rsaKeys = [
            'private' => DanaSandboxKeyMaterialFixture::PrivateKey,
            'public' => DanaSandboxKeyMaterialFixture::PublicKey,
        ];

        $payload = [
            'environment' => 'production',
            'idempotency_key' => 'conf-prod-sec-001',
            'partner_id' => '200000000002',
            'client_id' => 'client-id-prod-001',
            'client_secret' => 'super-secret-client-token',
            'merchant_id' => '216620000000000000002',
            'origin' => 'https://live.test',
            'channel_id' => 'DANA_ONLINE_PROD',
            'notification_url' => 'https://live.test/notify',
            'callback_url' => 'https://live.test/finish',
            'mcc' => '5411',
            'enabled' => true,
            'private_key' => $rsaKeys['private'],
            'dana_webhook_public_key' => $rsaKeys['public'],
        ];

        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", $payload)
            ->assertOk()
            ->assertDontSee('super-secret-client-token');

        // Check stored configuration endpoint
        $this->withToken(self::ServiceToken)
            ->getJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration?environment=production")
            ->assertOk()
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.configuration.client_id', 'client-id-prod-001')
            ->assertJsonPath('data.configuration.client_secret_configured', true)
            ->assertJsonMissingPath('data.configuration.client_secret')
            ->assertDontSee('super-secret-client-token');

        // Check DB storage is encrypted
        $config = MerchantPaymentConfig::query()
            ->where('merchant_id', $merchantId)
            ->where('environment', 'production')
            ->firstOrFail();
        $this->assertNotSame('super-secret-client-token', $config->getRawOriginal('encrypted_credentials'));
        $this->assertSame('super-secret-client-token', $config->encrypted_credentials['client_secret']);
        $this->assertSame('client-id-prod-001', $config->encrypted_credentials['client_id']);

        // Update with empty/null client_secret preserves existing client_secret
        $updatePayload = [
            'environment' => 'production',
            'idempotency_key' => 'conf-prod-sec-002',
            'partner_id' => '200000000002',
            'merchant_id' => '216620000000000000002',
            'origin' => 'https://live.test',
            'channel_id' => 'DANA_ONLINE_PROD',
            'notification_url' => 'https://live.test/notify-updated',
            'callback_url' => 'https://live.test/finish',
            'mcc' => '5411',
            'enabled' => true,
            'private_key' => null,
            'dana_webhook_public_key' => null,
            'client_secret' => null,
        ];

        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", $updatePayload)
            ->assertOk();

        $freshConfig = $config->fresh();
        $this->assertSame('super-secret-client-token', $freshConfig->encrypted_credentials['client_secret']);
        $this->assertSame('https://live.test/notify-updated', $freshConfig->encrypted_credentials['notification_url']);
    }

    public function test_production_webhook_public_key_update_preserves_private_key_client_secret_store_id_and_sandbox_isolation(): void
    {
        $provisionResp = $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', [
                'code' => 'MRC-ISO-TEST-001',
                'name' => 'Toko Isolasi Test',
                'email' => 'iso@test.com',
                'idempotency_key' => 'idemp-iso-001',
            ])
            ->assertStatus(Response::HTTP_CREATED);

        $merchantId = $provisionResp->json('data.merchant_id');

        // 1. Configure Sandbox
        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", [
                'environment' => 'sandbox',
                'idempotency_key' => 'conf-sbx-iso-001',
                'partner_id' => 'sbx-partner-100',
                'merchant_id' => 'sbx-mid-100',
                'origin' => 'https://sbx.iso.test',
                'channel_id' => 'DANA_SBX',
                'external_store_id' => 'STORE_SANDBOX_100',
                'sub_merchant_id' => 'SUB_SANDBOX_100',
                'notification_url' => 'https://sbx.iso.test/notify',
                'callback_url' => 'https://sbx.iso.test/finish',
                'mcc' => '5411',
                'enabled' => true,
                'private_key' => DanaSandboxKeyMaterialFixture::PrivateKey,
                'dana_webhook_public_key' => DanaSandboxKeyMaterialFixture::PublicKey,
            ])
            ->assertOk();

        // 2. Configure Production with distinct credentials
        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", [
                'environment' => 'production',
                'idempotency_key' => 'conf-prod-iso-001',
                'partner_id' => 'prod-partner-200',
                'client_id' => 'client-prod-200',
                'client_secret' => 'initial-prod-secret',
                'merchant_id' => 'prod-mid-200',
                'origin' => 'https://prod.iso.test',
                'channel_id' => 'DANA_PROD',
                'external_store_id' => 'STORE_PROD_200',
                'sub_merchant_id' => 'SUB_PROD_200',
                'notification_url' => 'https://prod.iso.test/notify',
                'callback_url' => 'https://prod.iso.test/finish',
                'mcc' => '5411',
                'enabled' => true,
                'private_key' => DanaSandboxKeyMaterialFixture::PrivateKey,
                'dana_webhook_public_key' => DanaSandboxKeyMaterialFixture::PublicKey,
            ])
            ->assertOk();

        // Distinct valid RSA-2048 public key for update test
        $newPublicKey = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEArtXD6xu8tsNK6pTY6o0K
As4LDImOiKL5xYxDVFwPGVvXNHowqWgoq5t961RC1xysE4rScf4sJFHJ+0n/qg0c
zp97T0FGX6fvbMJZW491ROfpdoopWvksF2lW6PsbnwuMmebzqupkY5HR68NBdJyA
1Rh4UwraH66XQfTGDTNBVmIHwqHqPA1acPRB44ktWkDmlnNe/ASsXKWGMct393w0
5uyHgctzo3RFy2y0Es0hwANS3qs83IPym2EYZgoegY92uodtOBBePb/WPj2KwVdT
PuSAsRlJI8lf1PyV3GvYbO2tezG+4V82xGkEvy/VJpwrXfJ/rzt61Zxt8UJRrvI5
TwIDAQAB
-----END PUBLIC KEY-----
PEM;

        // 3. Partial update on Production changing ONLY dana_webhook_public_key:
        // private_key is null, client_secret is null, external_store_id is '', sub_merchant_id is null
        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", [
                'environment' => 'production',
                'idempotency_key' => 'conf-prod-iso-002',
                'partner_id' => 'prod-partner-200',
                'merchant_id' => 'prod-mid-200',
                'origin' => 'https://prod.iso.test',
                'channel_id' => 'DANA_PROD',
                'external_store_id' => '',
                'sub_merchant_id' => null,
                'notification_url' => 'https://prod.iso.test/notify-v2',
                'callback_url' => 'https://prod.iso.test/finish',
                'mcc' => '5411',
                'enabled' => true,
                'private_key' => null,
                'dana_webhook_public_key' => $newPublicKey,
                'client_secret' => null,
            ])
            ->assertOk();

        // 4. Assert Production config:
        $prodConfig = MerchantPaymentConfig::query()
            ->where('merchant_id', $merchantId)
            ->where('environment', 'production')
            ->firstOrFail();

        $this->assertSame($newPublicKey, $prodConfig->encrypted_credentials['dana_webhook_public_key']);
        $this->assertSame(DanaSandboxKeyMaterialFixture::PrivateKey, $prodConfig->encrypted_credentials['private_key'], 'Private key production must not be altered');
        $this->assertSame('initial-prod-secret', $prodConfig->encrypted_credentials['client_secret'], 'Client secret production must not be altered');
        $this->assertSame('STORE_PROD_200', $prodConfig->encrypted_credentials['external_store_id'], 'External store ID must not be wiped without explicit clear');
        $this->assertSame('SUB_PROD_200', $prodConfig->encrypted_credentials['sub_merchant_id'], 'Sub merchant ID must not be wiped without explicit clear');

        // 5. Assert Sandbox config is 100% untouched:
        $sandboxConfig = MerchantPaymentConfig::query()
            ->where('merchant_id', $merchantId)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        $this->assertSame(DanaSandboxKeyMaterialFixture::PublicKey, $sandboxConfig->encrypted_credentials['dana_webhook_public_key'], 'Sandbox public key must not change');
        $this->assertSame(DanaSandboxKeyMaterialFixture::PrivateKey, $sandboxConfig->encrypted_credentials['private_key'], 'Sandbox private key must not change');
        $this->assertSame('STORE_SANDBOX_100', $sandboxConfig->encrypted_credentials['external_store_id'], 'Sandbox external store ID must not change');
        $this->assertSame('SUB_SANDBOX_100', $sandboxConfig->encrypted_credentials['sub_merchant_id'], 'Sandbox sub merchant ID must not change');
        $this->assertSame('https://sbx.iso.test/notify', $sandboxConfig->encrypted_credentials['notification_url']);
    }

    public function test_explicit_store_id_clearing_via_flags(): void
    {
        $provisionResp = $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', [
                'code' => 'MRC-CLR-TEST-001',
                'name' => 'Toko Clear Store ID Test',
                'email' => 'clear@test.com',
                'idempotency_key' => 'idemp-clr-001',
            ])
            ->assertStatus(Response::HTTP_CREATED);

        $merchantId = $provisionResp->json('data.merchant_id');

        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", [
                'environment' => 'production',
                'idempotency_key' => 'conf-prod-clr-001',
                'partner_id' => 'prod-partner-300',
                'merchant_id' => 'prod-mid-300',
                'origin' => 'https://prod.clr.test',
                'channel_id' => 'DANA_PROD',
                'external_store_id' => 'STORE_TO_BE_CLEARED',
                'sub_merchant_id' => 'SUB_TO_BE_CLEARED',
                'notification_url' => 'https://prod.clr.test/notify',
                'callback_url' => 'https://prod.clr.test/finish',
                'mcc' => '5411',
                'enabled' => true,
                'private_key' => DanaSandboxKeyMaterialFixture::PrivateKey,
                'dana_webhook_public_key' => DanaSandboxKeyMaterialFixture::PublicKey,
            ])
            ->assertOk();

        // Now explicitly clear them with clear flags
        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", [
                'environment' => 'production',
                'idempotency_key' => 'conf-prod-clr-002',
                'partner_id' => 'prod-partner-300',
                'merchant_id' => 'prod-mid-300',
                'origin' => 'https://prod.clr.test',
                'channel_id' => 'DANA_PROD',
                'notification_url' => 'https://prod.clr.test/notify',
                'callback_url' => 'https://prod.clr.test/finish',
                'mcc' => '5411',
                'enabled' => true,
                'clear_external_store_id' => true,
                'clear_sub_merchant_id' => true,
            ])
            ->assertOk();

        $config = MerchantPaymentConfig::query()
            ->where('merchant_id', $merchantId)
            ->where('environment', 'production')
            ->firstOrFail();

        $this->assertNull($config->encrypted_credentials['external_store_id']);
        $this->assertNull($config->encrypted_credentials['sub_merchant_id']);
    }

    public function test_production_webhook_public_key_rejects_invalid_rsa(): void
    {
        $provisionResp = $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', [
                'code' => 'MRC-RSA-FAIL-001',
                'name' => 'Toko RSA Fail Test',
                'email' => 'rsa@test.com',
                'idempotency_key' => 'idemp-rsafail-001',
            ])
            ->assertStatus(Response::HTTP_CREATED);

        $merchantId = $provisionResp->json('data.merchant_id');

        // Initial setup
        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", [
                'environment' => 'production',
                'idempotency_key' => 'conf-rsafail-001',
                'partner_id' => 'prod-partner-400',
                'merchant_id' => 'prod-mid-400',
                'origin' => 'https://prod.rsa.test',
                'channel_id' => 'DANA_PROD',
                'notification_url' => 'https://prod.rsa.test/notify',
                'callback_url' => 'https://prod.rsa.test/finish',
                'mcc' => '5411',
                'enabled' => true,
                'private_key' => DanaSandboxKeyMaterialFixture::PrivateKey,
                'dana_webhook_public_key' => DanaSandboxKeyMaterialFixture::PublicKey,
            ])
            ->assertOk();

        // Update with private key pasted into public key field
        $this->withToken(self::ServiceToken)
            ->putJson("/api/v1/internal/merchants/{$merchantId}/dana-configuration", [
                'environment' => 'production',
                'idempotency_key' => 'conf-rsafail-002',
                'partner_id' => 'prod-partner-400',
                'merchant_id' => 'prod-mid-400',
                'origin' => 'https://prod.rsa.test',
                'channel_id' => 'DANA_PROD',
                'notification_url' => 'https://prod.rsa.test/notify',
                'callback_url' => 'https://prod.rsa.test/finish',
                'mcc' => '5411',
                'enabled' => true,
                'dana_webhook_public_key' => DanaSandboxKeyMaterialFixture::PrivateKey, // WRONG: private key as public key
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('message', 'Format PEM bukan public key SPKI atau PKCS#1 yang didukung.');
    }
}
