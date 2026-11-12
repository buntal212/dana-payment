<?php

namespace Tests\Feature;

use App\Models\InternalMerchantApiKeyIssuance;
use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantManagementAudit;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Services\DanaSnapSignatureService;
use App\Services\MerchantApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_issue_rejects_unmanaged_invalid_environment_and_larissama_without_creating_keys(): void
    {
        $unmanaged = Merchant::factory()->create();
        $this->issue($unmanaged, 'issue-unmanaged')->assertNotFound();
        $merchant = $this->merchant();
        $this->withToken(self::ServiceToken)->withHeader('Idempotency-Key', 'issue-staging')->postJson($this->apiKeysUrl($merchant), $this->issuePayload(['environment' => 'staging']))->assertUnprocessable();
        $protected = $this->merchant(['code' => 'larissama']);
        $this->issue($protected, 'issue-larissama')->assertConflict();
        $this->assertDatabaseCount('internal_merchant_api_key_issuances', 0);
        $this->assertDatabaseCount('merchant_api_keys', 0);
    }

    public function test_issue_and_confirm_production_api_key_persists_environment_and_activates(): void
    {
        $merchant = $this->merchant();
        $response = $this->issue($merchant, 'issue-prod-1', ['environment' => 'production', 'name' => 'UDEV Production Key'])
            ->assertCreated()
            ->assertJsonPath('data.issuance.environment', 'production');

        $plaintext = $response->json('data.api_key');
        $issuanceId = $response->json('data.issuance.id');
        $merchantApiKeyId = $response->json('data.issuance.merchant_api_key_id');

        $this->assertDatabaseHas('merchant_api_keys', [
            'id' => $merchantApiKeyId,
            'merchant_id' => $merchant->id,
            'environment' => 'production',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('internal_merchant_api_key_issuances', [
            'id' => $issuanceId,
            'environment' => 'production',
            'status' => 'pending',
            'merchant_api_key_id' => $merchantApiKeyId,
        ]);

        // Key is not active yet
        $this->assertNull(app(MerchantApiKeyService::class)->authenticate($plaintext, 'payments:create', 'production'));

        // Confirm issuance
        $this->withToken(self::ServiceToken)
            ->withHeader('Idempotency-Key', 'confirm-prod-1')
            ->postJson($this->issuanceUrl($merchant, $issuanceId).'/confirm', ['confirm_storage' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.environment', 'production');

        $key = MerchantApiKey::query()->findOrFail($merchantApiKeyId);
        $this->assertTrue($key->is_active);
        $this->assertSame('production', $key->environment);

        // Key is now usable for production
        $this->assertNotNull(app(MerchantApiKeyService::class)->authenticate($plaintext, 'payments:create', 'production'));
        // But not for sandbox
        $this->assertNull(app(MerchantApiKeyService::class)->authenticate($plaintext, 'payments:create', 'sandbox'));
    }

    public function test_environment_isolation_prevents_production_key_from_sandbox_and_vice_versa(): void
    {
        $signatureMock = $this->createMock(DanaSnapSignatureService::class);
        $signatureMock->method('signTransactional')->willReturn('test-signature');
        $this->app->instance(DanaSnapSignatureService::class, $signatureMock);

        Http::fake([
            '*' => Http::response([
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-TEST-ORDER-1',
                'webRedirectUrl' => 'https://checkout.dana.id/test',
            ]),
        ]);

        $merchant = $this->merchant();

        // Setup both Sandbox and Production merchant configs
        MerchantPaymentConfig::factory()->for($merchant)->create([
            'environment' => 'sandbox',
            'partner_id' => 'sbx-partner',
            'callback_url' => 'https://example.com/callback',
            'enabled' => true,
            'encrypted_credentials' => [
                'merchant_id' => 'sbx-mid',
                'private_key' => 'sbx-key',
                'dana_webhook_public_key' => 'sbx-pub',
                'origin' => 'example.com',
                'channel_id' => '95221',
                'notification_url' => 'https://example.com/notify',
                'mcc' => '5411',
            ],
        ]);
        MerchantPaymentConfig::factory()->for($merchant)->create([
            'environment' => 'production',
            'partner_id' => 'prod-partner',
            'callback_url' => 'https://example.com/callback',
            'enabled' => true,
            'encrypted_credentials' => [
                'merchant_id' => 'prod-mid',
                'private_key' => 'prod-key',
                'dana_webhook_public_key' => 'prod-pub',
                'origin' => 'example.com',
                'channel_id' => '95221',
                'notification_url' => 'https://example.com/notify',
                'mcc' => '5411',
            ],
        ]);

        // Issue and confirm a Sandbox Key
        $sbxIssuance = $this->issue($merchant, 'idemp-issue-sbx', ['environment' => 'sandbox']);
        $sbxKey = $sbxIssuance->json('data.api_key');
        $this->withToken(self::ServiceToken)
            ->withHeader('Idempotency-Key', 'idemp-confirm-sbx')
            ->postJson($this->issuanceUrl($merchant, $sbxIssuance->json('data.issuance.id')).'/confirm', ['confirm_storage' => true])
            ->assertOk();

        // Issue and confirm a Production Key
        $prodIssuance = $this->issue($merchant, 'idemp-issue-prod', ['environment' => 'production']);
        $prodKey = $prodIssuance->json('data.api_key');
        $this->withToken(self::ServiceToken)
            ->withHeader('Idempotency-Key', 'idemp-confirm-prod')
            ->postJson($this->issuanceUrl($merchant, $prodIssuance->json('data.issuance.id')).'/confirm', ['confirm_storage' => true])
            ->assertOk();

        // 1. Sandbox key can create sandbox payment
        $sbxPaymentResp = $this->withToken($sbxKey)
            ->withHeader('Idempotency-Key', 'pay-sbx-1')
            ->postJson('/api/v1/payments', [
                'merchant_order_id' => 'ORDER-SBX-101',
                'amount_minor' => 10000,
                'currency' => 'IDR',
                'environment' => 'sandbox',
            ])
            ->assertSuccessful();

        $sbxPaymentId = $sbxPaymentResp->json('data.id');
        $this->assertDatabaseHas('payments', ['public_id' => $sbxPaymentId, 'environment' => 'sandbox']);

        // 2. Sandbox key CANNOT create production payment -> 403 Forbidden
        $this->withToken($sbxKey)
            ->withHeader('Idempotency-Key', 'pay-sbx-fail-prod')
            ->postJson('/api/v1/payments', [
                'merchant_order_id' => 'ORDER-SBX-PROD-FAIL',
                'amount_minor' => 10000,
                'currency' => 'IDR',
                'environment' => 'production',
            ])
            ->assertForbidden();

        // 3. Production key can create production payment
        $prodPaymentResp = $this->withToken($prodKey)
            ->withHeader('Idempotency-Key', 'pay-prod-1')
            ->postJson('/api/v1/payments', [
                'merchant_order_id' => 'ORDER-PROD-101',
                'amount_minor' => 20000,
                'currency' => 'IDR',
                'environment' => 'production',
            ])
            ->assertSuccessful();

        $prodPaymentId = $prodPaymentResp->json('data.id');
        $this->assertDatabaseHas('payments', ['public_id' => $prodPaymentId, 'environment' => 'production']);

        // 4. Production key CANNOT create sandbox payment -> 403 Forbidden
        $this->withToken($prodKey)
            ->withHeader('Idempotency-Key', 'pay-prod-fail-sbx')
            ->postJson('/api/v1/payments', [
                'merchant_order_id' => 'ORDER-PROD-SBX-FAIL',
                'amount_minor' => 20000,
                'currency' => 'IDR',
                'environment' => 'sandbox',
            ])
            ->assertForbidden();

        // 5. Production key omitting environment defaults to sandbox request -> 403 Forbidden
        $this->withToken($prodKey)
            ->withHeader('Idempotency-Key', 'pay-prod-omit-env')
            ->postJson('/api/v1/payments', [
                'merchant_order_id' => 'ORDER-PROD-OMIT-FAIL',
                'amount_minor' => 20000,
                'currency' => 'IDR',
            ])
            ->assertForbidden();

        // 6. Cross-environment reading by ID is forbidden (404 Not Found)
        // Sandbox key trying to access Production payment
        $this->withToken($sbxKey)
            ->getJson("/api/v1/payments/{$prodPaymentId}")
            ->assertNotFound();

        // Production key trying to access Sandbox payment
        $this->withToken($prodKey)
            ->getJson("/api/v1/payments/{$sbxPaymentId}")
            ->assertNotFound();

        // 7. Listing payments is strictly isolated per environment
        $sbxList = $this->withToken($sbxKey)->getJson('/api/v1/payments')->assertOk();
        $sbxIds = collect($sbxList->json('data'))->pluck('id')->all();
        $this->assertContains($sbxPaymentId, $sbxIds);
        $this->assertNotContains($prodPaymentId, $sbxIds);

        $prodList = $this->withToken($prodKey)->getJson('/api/v1/payments')->assertOk();
        $prodIds = collect($prodList->json('data'))->pluck('id')->all();
        $this->assertContains($prodPaymentId, $prodIds);
        $this->assertNotContains($sbxPaymentId, $prodIds);

        // 8. Passing contradicting ?environment= in GET is forbidden (403)
        $this->withToken($sbxKey)->getJson('/api/v1/payments?environment=production')->assertForbidden();
        $this->withToken($prodKey)->getJson('/api/v1/payments?environment=sandbox')->assertForbidden();
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

    private function issue(Merchant $merchant, string $idempotencyKey, array $overrides = []): TestResponse
    {
        return $this->withToken(self::ServiceToken)->withHeader('Idempotency-Key', $idempotencyKey)->postJson($this->apiKeysUrl($merchant), $this->issuePayload($overrides));
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
