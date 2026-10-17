<?php

namespace Tests\Feature;

use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantManagementAudit;
use App\Models\MerchantPaymentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\DanaSandboxKeyMaterialFixture;
use Tests\TestCase;

class InternalMerchantManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private const string ServiceToken = 'test-internal-management-token';

    protected function setUp(): void
    {
        parent::setUp();

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
                'merchants:api-keys:read',
                'merchants:api-keys:write',
            ],
        ]);
        config()->set('app.url', 'https://gateway.test');
        $this->app['url']->forceScheme('https');
    }

    public function test_management_endpoints_are_disabled_until_explicitly_enabled(): void
    {
        $merchant = $this->managedMerchant();
        config()->set('services.udev_internal.enabled', false);

        $this->withToken(self::ServiceToken)
            ->getJson($this->readinessUrl($merchant))
            ->assertNotFound();
    }

    public function test_management_endpoints_require_a_valid_service_token_and_ability(): void
    {
        $merchant = $this->managedMerchant();

        $this->getJson($this->readinessUrl($merchant))->assertUnauthorized();
        $this->getJson($this->configurationUrl($merchant))->assertUnauthorized();

        $this->withToken('not-the-service-token')
            ->getJson($this->readinessUrl($merchant))
            ->assertUnauthorized()
            ->assertDontSee('not-the-service-token')
            ->assertDontSee(self::ServiceToken);

        config()->set('services.udev_internal.abilities', ['merchants:read']);

        $this->withToken(self::ServiceToken)
            ->getJson($this->readinessUrl($merchant))
            ->assertForbidden();
        $this->withToken(self::ServiceToken)
            ->getJson($this->configurationUrl($merchant))
            ->assertForbidden();
    }

    public function test_management_endpoints_reject_non_https_requests_after_authentication(): void
    {
        $merchant = $this->managedMerchant();
        config()->set('app.url', 'http://gateway.test');
        $this->app['url']->forceScheme('http');

        $this->withToken(self::ServiceToken)
            ->getJson($this->readinessUrl($merchant))
            ->assertForbidden();
    }

    public function test_readiness_only_reports_safe_metadata_and_missing_components(): void
    {
        $merchant = $this->managedMerchant(['code' => 'udev-central']);

        $this->withToken(self::ServiceToken)
            ->getJson($this->readinessUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.merchant_code', 'udev-central')
            ->assertJsonPath('data.merchant_status', 'active')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.active_api_key_count', 0)
            ->assertJsonPath('data.dana_configuration.available', false)
            ->assertJsonPath('data.dana_configuration.enabled', false)
            ->assertJsonPath('data.readiness_status', 'not_ready')
            ->assertJsonFragment(['active_api_key'])
            ->assertJsonFragment(['dana_configuration'])
            ->assertJsonMissingPath('data.encrypted_credentials')
            ->assertJsonMissingPath('data.private_key');
    }

    public function test_readiness_allows_a_managed_suspended_merchant_but_marks_it_not_ready(): void
    {
        $merchant = $this->managedMerchant(['status' => 'suspended']);

        $this->withToken(self::ServiceToken)
            ->getJson($this->readinessUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.merchant_status', 'suspended')
            ->assertJsonPath('data.readiness_status', 'not_ready')
            ->assertJsonFragment(['merchant_active']);
    }

    public function test_unmanaged_or_unknown_merchant_cannot_be_accessed(): void
    {
        $unmanagedMerchant = Merchant::factory()->create();

        $this->withToken(self::ServiceToken)
            ->getJson($this->readinessUrl($unmanagedMerchant))
            ->assertNotFound();
        $this->withToken(self::ServiceToken)
            ->getJson('/api/v1/internal/merchants/999999/readiness')
            ->assertNotFound();
    }

    public function test_dana_configuration_metadata_isolated_and_never_exposes_secrets(): void
    {
        $merchant = $this->managedMerchant(['code' => 'udev-central']);
        $config = MerchantPaymentConfig::factory()->for($merchant, 'merchant')->create([
            'partner_id' => 'partner-sandbox-001',
            'callback_url' => 'https://udev.example/dana/callback',
            'enabled' => false,
            'encrypted_credentials' => [
                'merchant_id' => 'merchant-sandbox-001',
                'private_key' => $this->privateKey(),
                'dana_webhook_public_key' => $this->danaPublicKey(),
                'origin' => 'https://udev.example',
                'channel_id' => 'sandbox-channel',
                'notification_url' => 'https://dana.udumbara.my.id/api/v1/webhooks/dana',
                'mcc' => '7392',
            ],
        ]);
        $rawCredentials = $config->getRawOriginal('encrypted_credentials');

        $this->withToken(self::ServiceToken)
            ->getJson($this->configurationUrl($merchant))
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.merchant_code', 'udev-central')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.configuration_exists', true)
            ->assertJsonPath('data.configuration.partner_id', 'partner-sandbox-001')
            ->assertJsonPath('data.configuration.merchant_id', 'merchant-sandbox-001')
            ->assertJsonPath('data.configuration.private_key_configured', true)
            ->assertJsonPath('data.configuration.dana_webhook_public_key_configured', true)
            ->assertJsonMissingPath('data.configuration.private_key')
            ->assertJsonMissingPath('data.configuration.dana_webhook_public_key')
            ->assertDontSee($this->privateKey())
            ->assertDontSee($this->danaPublicKey());

        $this->assertSame($rawCredentials, $config->fresh()->getRawOriginal('encrypted_credentials'));

        $unmanaged = Merchant::factory()->create();
        $this->withToken(self::ServiceToken)
            ->getJson($this->configurationUrl($unmanaged))
            ->assertNotFound();

        $protected = $this->managedMerchant(['code' => 'larissama']);
        $this->withToken(self::ServiceToken)
            ->getJson($this->configurationUrl($protected))
            ->assertForbidden();
    }

    public function test_dana_configuration_metadata_reports_when_no_configuration_exists_and_requires_its_ability(): void
    {
        $merchant = $this->managedMerchant();

        $this->withToken(self::ServiceToken)
            ->getJson($this->configurationUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.configuration_exists', false)
            ->assertJsonPath('data.configuration', null);

        config()->set('services.udev_internal.abilities', ['merchants:credentials:write']);

        $this->withToken(self::ServiceToken)
            ->getJson($this->configurationUrl($merchant))
            ->assertForbidden();
    }

    public function test_sandbox_configuration_is_encrypted_idempotent_and_never_returned(): void
    {
        $merchant = $this->managedMerchant(['code' => 'udev-central']);
        $payload = $this->configurationPayload([
            'private_key' => $this->pemToBase64Der($this->privateKey()),
            'dana_webhook_public_key' => $this->pemToBase64Der($this->danaPublicKey()),
        ]);
        Log::spy();

        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($merchant), $payload)
            ->assertOk()
            ->assertJsonPath('data.merchant_code', 'udev-central')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.dana_configuration.available', true)
            ->assertJsonPath('data.dana_configuration.enabled', true)
            ->assertJsonPath('data.dana_configuration.ready', true)
            ->assertHeader('Cache-Control')
            ->assertDontSee($this->privateKey())
            ->assertDontSee($this->danaPublicKey())
            ->assertDontSee('merchant-sandbox-secret');

        $config = MerchantPaymentConfig::query()->sole();
        $this->assertNotSame($this->privateKey(), $config->getRawOriginal('encrypted_credentials'));
        $this->assertSame($this->privateKey(), $config->encrypted_credentials['private_key']);
        $audit = MerchantManagementAudit::query()->sole();
        $auditPayload = json_encode($audit->safe_details, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($this->privateKey(), $auditPayload);
        $this->assertStringNotContainsString($this->danaPublicKey(), $auditPayload);
        $this->assertStringNotContainsString('merchant-sandbox-secret', $auditPayload);
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');

        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($merchant), $payload)
            ->assertOk()
            ->assertDontSee($this->privateKey());

        $this->assertDatabaseCount('merchant_payment_configs', 1);
        $this->assertDatabaseCount('internal_merchant_management_requests', 1);
        $this->assertDatabaseCount('merchant_management_audits', 1);

        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($merchant), $this->configurationPayload(['mcc' => '7399']))
            ->assertConflict();
    }

    public function test_configuration_rejects_production_and_invalid_values_without_persisting(): void
    {
        $merchant = $this->managedMerchant();

        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($merchant), $this->configurationPayload(['environment' => 'production']))
            ->assertUnprocessable();
        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($merchant), $this->configurationPayload([
                'idempotency_key' => 'invalid-configuration-002',
                'notification_url' => 'http://merchant.test/notification',
            ]))
            ->assertUnprocessable();
        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($merchant), $this->configurationPayload([
                'idempotency_key' => 'invalid-configuration-003',
                'private_key' => 'bukan-pem',
            ]))
            ->assertUnprocessable();
        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($merchant), $this->configurationPayload([
                'idempotency_key' => 'invalid-configuration-004',
                'private_key' => null,
                'dana_webhook_public_key' => null,
            ]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('merchant_payment_configs', 0);
        $this->assertDatabaseCount('merchant_management_audits', 0);
    }

    public function test_configuration_update_with_empty_secret_fields_preserves_existing_encrypted_secrets(): void
    {
        $merchant = $this->managedMerchant();
        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($merchant), $this->configurationPayload())
            ->assertOk();

        $config = MerchantPaymentConfig::query()->sole();
        $rawCredentials = $config->getRawOriginal('encrypted_credentials');

        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($merchant), $this->configurationPayload([
                'idempotency_key' => 'configure-udev-central-002',
                'private_key' => null,
                'dana_webhook_public_key' => null,
                'enabled' => false,
            ]))
            ->assertOk()
            ->assertJsonPath('data.dana_configuration.enabled', false);

        $updated = $config->fresh();
        $this->assertSame($this->privateKey(), $updated->encrypted_credentials['private_key']);
        $this->assertSame($this->danaPublicKey(), $updated->encrypted_credentials['dana_webhook_public_key']);
        $this->assertNotSame('', $rawCredentials);
    }

    public function test_sensitive_management_mutations_are_rate_limited(): void
    {
        $merchant = $this->managedMerchant();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11']);
        $invalidPayload = $this->configurationPayload(['environment' => 'production']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withToken(self::ServiceToken)
                ->putJson($this->configurationUrl($merchant), $invalidPayload)
                ->assertUnprocessable();
        }

        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($merchant), $invalidPayload)
            ->assertStatus(429);
    }

    public function test_configuration_is_rejected_for_suspended_closed_and_protected_merchants(): void
    {
        foreach (['suspended', 'closed'] as $status) {
            $merchant = $this->managedMerchant(['status' => $status]);

            $this->withToken(self::ServiceToken)
                ->putJson($this->configurationUrl($merchant), $this->configurationPayload(['idempotency_key' => "{$status}-configuration"]))
                ->assertUnprocessable();
        }

        $protectedMerchant = $this->managedMerchant(['code' => 'larissama']);
        $existingConfig = MerchantPaymentConfig::factory()->for($protectedMerchant, 'merchant')->create();
        $rawCredentials = $existingConfig->getRawOriginal('encrypted_credentials');

        $this->withToken(self::ServiceToken)
            ->putJson($this->configurationUrl($protectedMerchant), $this->configurationPayload(['idempotency_key' => 'larissama-configuration']))
            ->assertForbidden();

        $this->assertSame($rawCredentials, $existingConfig->fresh()->getRawOriginal('encrypted_credentials'));
    }

    public function test_api_key_metadata_never_includes_hash_or_plaintext(): void
    {
        $merchant = $this->managedMerchant();
        $plaintextKey = 'dpk_fake_plaintext_key_for_test_only';
        $apiKey = MerchantApiKey::factory()->for($merchant, 'merchant')->create([
            'key_hash' => hash('sha256', $plaintextKey),
        ]);

        $this->withToken(self::ServiceToken)
            ->getJson($this->apiKeysUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.0.id', $apiKey->id)
            ->assertJsonPath('data.0.key_prefix', $apiKey->key_prefix)
            ->assertJsonMissingPath('data.0.key_hash')
            ->assertDontSee($plaintextKey)
            ->assertDontSee($apiKey->key_hash);
    }

    public function test_api_key_metadata_reports_the_persisted_inactive_state_and_readiness_excludes_it(): void
    {
        $merchant = $this->managedMerchant();
        $apiKey = MerchantApiKey::factory()->for($merchant, 'merchant')->create(['is_active' => false]);

        $this->withToken(self::ServiceToken)
            ->getJson($this->apiKeysUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.0.id', $apiKey->id)
            ->assertJsonPath('data.0.is_active', false);

        $this->withToken(self::ServiceToken)
            ->getJson($this->readinessUrl($merchant))
            ->assertOk()
            ->assertJsonPath('data.active_api_key_count', 0)
            ->assertJsonFragment(['active_api_key']);
    }

    public function test_api_key_revocation_requires_confirmation_and_preserves_last_or_recently_used_key(): void
    {
        $merchant = $this->managedMerchant();
        $lastKey = MerchantApiKey::factory()->for($merchant, 'merchant')->create();

        $this->withToken(self::ServiceToken)
            ->deleteJson($this->apiKeyUrl($merchant, $lastKey), ['idempotency_key' => 'revoke-confirmation'])
            ->assertUnprocessable();
        $this->assertNull($lastKey->fresh()->revoked_at);

        $this->withToken(self::ServiceToken)
            ->deleteJson($this->apiKeyUrl($merchant, $lastKey), $this->revokePayload('revoke-last-key'))
            ->assertConflict();
        $this->assertNull($lastKey->fresh()->revoked_at);

        $backupKey = MerchantApiKey::factory()->for($merchant, 'merchant')->create();
        $lastKey->update(['last_used_at' => now()]);

        $this->withToken(self::ServiceToken)
            ->deleteJson($this->apiKeyUrl($merchant, $lastKey), $this->revokePayload('revoke-recent-key'))
            ->assertConflict();
        $this->assertNull($lastKey->fresh()->revoked_at);
        $this->assertNull($backupKey->fresh()->revoked_at);
    }

    public function test_api_key_revocation_is_idempotent_audited_and_cannot_change_larissama(): void
    {
        $merchant = $this->managedMerchant();
        $apiKey = MerchantApiKey::factory()->for($merchant, 'merchant')->create(['last_used_at' => now()->subMinutes(16)]);
        MerchantApiKey::factory()->for($merchant, 'merchant')->create();
        $payload = $this->revokePayload('revoke-safe-key');

        $this->withToken(self::ServiceToken)
            ->deleteJson($this->apiKeyUrl($merchant, $apiKey), $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $apiKey->id)
            ->assertJsonPath('data.key_prefix', $apiKey->key_prefix)
            ->assertJsonMissingPath('data.key_hash');
        $this->assertNotNull($apiKey->fresh()->revoked_at);

        $this->withToken(self::ServiceToken)
            ->deleteJson($this->apiKeyUrl($merchant, $apiKey), $payload)
            ->assertOk();
        $this->assertDatabaseCount('merchant_management_audits', 1);

        $protectedMerchant = $this->managedMerchant(['code' => 'larissama']);
        $protectedKey = MerchantApiKey::factory()->for($protectedMerchant, 'merchant')->create(['last_used_at' => now()->subMinutes(16)]);
        MerchantApiKey::factory()->for($protectedMerchant, 'merchant')->create();

        $this->withToken(self::ServiceToken)
            ->deleteJson($this->apiKeyUrl($protectedMerchant, $protectedKey), $this->revokePayload('revoke-larissama-key'))
            ->assertForbidden();
        $this->assertNull($protectedKey->fresh()->revoked_at);
    }

    private function managedMerchant(array $attributes = []): Merchant
    {
        $merchant = Merchant::factory()->create($attributes);
        InternalMerchantProvisioningRequest::factory()->for($merchant, 'merchant')->create([
            'service_id' => 'udev-billing',
            'environment' => 'sandbox',
        ]);

        return $merchant;
    }

    /** @return array<string, bool|string> */
    private function configurationPayload(array $overrides = []): array
    {
        return [
            'environment' => 'sandbox',
            'idempotency_key' => 'configure-udev-central-001',
            'partner_id' => 'partner-sandbox-001',
            'merchant_id' => 'merchant-sandbox-secret',
            'private_key' => $this->privateKey(),
            'dana_webhook_public_key' => $this->danaPublicKey(),
            'origin' => 'https://merchant.test',
            'channel_id' => 'channel-sandbox-001',
            'notification_url' => 'https://merchant.test/dana/notification',
            'callback_url' => 'https://merchant.test/dana/return',
            'mcc' => '7392',
            'enabled' => true,
            ...$overrides,
        ];
    }

    /** @return array<string, bool|string> */
    private function revokePayload(string $idempotencyKey): array
    {
        return ['idempotency_key' => $idempotencyKey, 'confirm_revoke' => true];
    }

    private function readinessUrl(Merchant $merchant): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/readiness";
    }

    private function configurationUrl(Merchant $merchant): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/dana-configuration";
    }

    private function apiKeysUrl(Merchant $merchant): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/api-keys";
    }

    private function apiKeyUrl(Merchant $merchant, MerchantApiKey $apiKey): string
    {
        return $this->apiKeysUrl($merchant)."/{$apiKey->id}";
    }

    private function privateKey(): string
    {
        return DanaSandboxKeyMaterialFixture::PrivateKey;
    }

    private function danaPublicKey(): string
    {
        return DanaSandboxKeyMaterialFixture::PublicKey;
    }

    private function pemToBase64Der(string $pem): string
    {
        $base64 = preg_replace('/-----BEGIN [A-Z ]+-----|-----END [A-Z ]+-----|\s+/', '', $pem);
        self::assertIsString($base64);

        return $base64;
    }
}
