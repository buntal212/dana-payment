<?php

namespace Tests\Feature;

use App\Models\InternalMerchantApiKeyIssuance;
use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantManagementAudit;
use App\Services\MerchantApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InternalMerchantApiKeyIssuanceApiTest extends TestCase
{
    use RefreshDatabase;

    private const string ServiceToken = 'test-internal-issuance-token';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.udev_internal', ['enabled' => true, 'service_id' => 'udev-billing', 'token' => self::ServiceToken, 'abilities' => ['merchants:api-keys:issue'], 'api_key_issuance_pending_ttl_minutes' => 10]);
        config()->set('app.url', 'https://gateway.test');
        $this->app['url']->forceScheme('https');
    }

    public function test_issue_requires_internal_authentication_ability_https_and_idempotency_header(): void
    {
        $merchant = $this->merchant();
        $this->postJson($this->apiKeysUrl($merchant), $this->issuePayload())->assertUnauthorized();
        $this->withToken(self::ServiceToken)->postJson($this->apiKeysUrl($merchant), $this->issuePayload())->assertUnprocessable();
        config()->set('services.udev_internal.abilities', ['merchants:api-keys:read']);
        $this->withToken(self::ServiceToken)->withHeader('Idempotency-Key', 'issue-auth')->postJson($this->apiKeysUrl($merchant), $this->issuePayload())->assertForbidden();
    }

    public function test_issue_reserves_a_stable_api_key_id_then_confirm_activates_the_same_key(): void
    {
        $merchant = $this->merchant();
        Log::spy();
        $response = $this->issue($merchant, 'issue-confirm-1')->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $plaintext = $response->json('data.api_key');
        $issuanceId = $response->json('data.issuance.id');
        $merchantApiKeyId = $response->json('data.issuance.merchant_api_key_id');
        $this->assertIsString($plaintext);
        $this->assertIsInt($merchantApiKeyId);
        $this->assertDatabaseHas('merchant_api_keys', ['id' => $merchantApiKeyId, 'merchant_id' => $merchant->id, 'is_active' => false]);
        $this->assertDatabaseHas('internal_merchant_api_key_issuances', ['id' => $issuanceId, 'status' => 'pending', 'merchant_api_key_id' => $merchantApiKeyId]);
        $this->assertNull(app(MerchantApiKeyService::class)->authenticate($plaintext, 'payments:create'));

        $this->withToken(self::ServiceToken)
            ->getJson($this->issuanceUrl($merchant, $issuanceId))
            ->assertOk()
            ->assertJsonPath('data.merchant_api_key_id', $merchantApiKeyId)
            ->assertJsonMissingPath('data.api_key')
            ->assertDontSee($plaintext);

        $this->issue($merchant, 'issue-confirm-1')
            ->assertOk()
            ->assertJsonPath('data.issuance.merchant_api_key_id', $merchantApiKeyId)
            ->assertJsonMissingPath('data.api_key')
            ->assertDontSee($plaintext);
        $this->withToken(self::ServiceToken)
            ->withHeader('Idempotency-Key', 'confirm-1')
            ->postJson($this->issuanceUrl($merchant, $issuanceId).'/confirm', ['confirm_storage' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.merchant_api_key_id', $merchantApiKeyId)
            ->assertDontSee($plaintext);
        $key = MerchantApiKey::query()->findOrFail($merchantApiKeyId);
        $this->assertTrue($key->is_active);
        $this->assertSame(hash('sha256', $plaintext), $key->key_hash);
        $this->assertNotNull(app(MerchantApiKeyService::class)->authenticate($plaintext, 'payments:create'));
        $this->assertDatabaseHas('internal_merchant_api_key_issuances', ['id' => $issuanceId, 'status' => 'confirmed', 'merchant_api_key_id' => $merchantApiKeyId]);
        $auditPayload = json_encode(MerchantManagementAudit::query()->where('operation', 'merchant.api_key_issued')->sole()->safe_details, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($plaintext, $auditPayload);
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');

        $this->withToken(self::ServiceToken)
            ->withHeader('Idempotency-Key', 'confirm-1')
            ->postJson($this->issuanceUrl($merchant, $issuanceId).'/confirm', ['confirm_storage' => true])
            ->assertOk()
            ->assertJsonPath('data.merchant_api_key_id', $merchantApiKeyId)
            ->assertJsonMissingPath('data.api_key');
    }

    public function test_issue_rejects_unmanaged_production_and_larissama_without_creating_keys(): void
    {
        $unmanaged = Merchant::factory()->create();
        $this->issue($unmanaged, 'issue-unmanaged')->assertNotFound();
        $merchant = $this->merchant();
        $this->withToken(self::ServiceToken)->withHeader('Idempotency-Key', 'issue-production')->postJson($this->apiKeysUrl($merchant), $this->issuePayload(['environment' => 'production']))->assertUnprocessable();
        $protected = $this->merchant(['code' => 'larissama']);
        $this->issue($protected, 'issue-larissama')->assertConflict();
        $this->assertDatabaseCount('internal_merchant_api_key_issuances', 0);
        $this->assertDatabaseCount('merchant_api_keys', 0);
    }

    public function test_status_is_isolated_and_pending_issuance_can_be_cancelled_idempotently(): void
    {
        $first = $this->merchant(['code' => 'udev-first']);
        $second = $this->merchant(['code' => 'udev-second']);
        $issuanceId = $this->issue($first, 'issue-cancel')->json('data.issuance.id');
        $this->withToken(self::ServiceToken)->getJson($this->issuanceUrl($second, $issuanceId))->assertNotFound();
        $url = $this->issuanceUrl($first, $issuanceId).'/cancel';
        $merchantApiKeyId = InternalMerchantApiKeyIssuance::query()->findOrFail($issuanceId)->merchant_api_key_id;
        $this->withToken(self::ServiceToken)->withHeader('Idempotency-Key', 'cancel-1')->postJson($url, ['confirm_cancel' => true])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.merchant_api_key_id', $merchantApiKeyId);
        $this->withToken(self::ServiceToken)->withHeader('Idempotency-Key', 'cancel-1')->postJson($url, ['confirm_cancel' => true])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.merchant_api_key_id', $merchantApiKeyId);
        $this->assertFalse(MerchantApiKey::query()->findOrFail($merchantApiKeyId)->is_active);
    }

    public function test_expired_pending_issuance_cannot_be_confirmed_or_activated(): void
    {
        $merchant = $this->merchant();
        $response = $this->issue($merchant, 'issue-expired');
        $issuanceId = $response->json('data.issuance.id');
        $merchantApiKeyId = $response->json('data.issuance.merchant_api_key_id');
        InternalMerchantApiKeyIssuance::query()->findOrFail($issuanceId)->update(['pending_expires_at' => now()->subMinute()]);

        $this->withToken(self::ServiceToken)->getJson($this->issuanceUrl($merchant, $issuanceId))->assertOk()->assertJsonPath('data.status', 'expired')->assertJsonPath('data.merchant_api_key_id', $merchantApiKeyId)->assertJsonMissingPath('data.api_key')->assertHeader('Cache-Control', 'no-store, private');
        $this->withToken(self::ServiceToken)->withHeader('Idempotency-Key', 'expired-confirm')->postJson($this->issuanceUrl($merchant, $issuanceId).'/confirm', ['confirm_storage' => true])->assertUnprocessable();
        $this->assertFalse(MerchantApiKey::query()->findOrFail($merchantApiKeyId)->is_active);
    }

    public function test_independent_issuance_reservations_use_distinct_final_api_key_ids(): void
    {
        $firstMerchant = $this->merchant(['code' => 'udev-concurrent-first']);
        $secondMerchant = $this->merchant(['code' => 'udev-concurrent-second']);

        $firstApiKeyId = $this->issue($firstMerchant, 'issue-concurrent-first')->assertCreated()->json('data.issuance.merchant_api_key_id');
        $secondApiKeyId = $this->issue($secondMerchant, 'issue-concurrent-second')->assertCreated()->json('data.issuance.merchant_api_key_id');

        $this->assertNotSame($firstApiKeyId, $secondApiKeyId);
        $this->assertDatabaseCount('merchant_api_keys', 2);
    }

    public function test_existing_active_api_keys_remain_usable_after_pending_reservation(): void
    {
        $merchant = $this->merchant();
        $existing = app(MerchantApiKeyService::class)->create($merchant, 'existing-key', abilities: ['payments:create']);

        $this->issue($merchant, 'issue-with-existing-key')->assertCreated();

        $this->assertNotNull(app(MerchantApiKeyService::class)->authenticate($existing['api_key'], 'payments:create'));
    }

    private function merchant(array $attributes = []): Merchant
    {
        $merchant = Merchant::factory()->create($attributes);
        InternalMerchantProvisioningRequest::factory()->for($merchant, 'merchant')->create(['service_id' => 'udev-billing', 'environment' => 'sandbox']);

        return $merchant;
    }

    private function issue(Merchant $merchant, string $idempotencyKey): TestResponse
    {
        return $this->withToken(self::ServiceToken)->withHeader('Idempotency-Key', $idempotencyKey)->postJson($this->apiKeysUrl($merchant), $this->issuePayload());
    }

    private function issuePayload(array $overrides = []): array
    {
        return ['environment' => 'sandbox', 'name' => 'UDEV Sandbox Checkout', 'abilities' => ['payments:*'], ...$overrides];
    }

    private function apiKeysUrl(Merchant $merchant): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/api-keys";
    }

    private function issuanceUrl(Merchant $merchant, int $issuanceId): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/api-key-issuances/{$issuanceId}";
    }
}
