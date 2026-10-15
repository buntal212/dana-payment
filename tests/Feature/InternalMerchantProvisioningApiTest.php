<?php

namespace Tests\Feature;

use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\MerchantProvisioningAudit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class InternalMerchantProvisioningApiTest extends TestCase
{
    use RefreshDatabase;

    private const string ServiceToken = 'test-internal-service-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.udev_internal', [
            'enabled' => true,
            'service_id' => 'udev-billing',
            'token' => self::ServiceToken,
            'abilities' => ['merchants:read', 'merchants:write'],
        ]);
    }

    public function test_internal_merchant_routes_require_a_dedicated_service_credential(): void
    {
        $this->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertUnauthorized();

        config()->set('services.udev_internal.token', null);

        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertServiceUnavailable();
    }

    public function test_internal_routes_remain_unavailable_until_explicitly_enabled(): void
    {
        config()->set('services.udev_internal.enabled', false);

        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertNotFound();
    }

    public function test_invalid_service_credential_is_rejected_without_being_disclosed(): void
    {
        $invalidToken = 'invalid-internal-service-token';

        $this->withToken($invalidToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertUnauthorized()
            ->assertDontSee($invalidToken)
            ->assertDontSee(self::ServiceToken);
    }

    public function test_service_scopes_are_enforced_for_read_and_write_operations(): void
    {
        config()->set('services.udev_internal.abilities', ['merchants:read']);

        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertForbidden();

        config()->set('services.udev_internal.abilities', ['merchants:write']);

        $this->withToken(self::ServiceToken)
            ->getJson('/api/v1/internal/merchants')
            ->assertForbidden();
    }

    public function test_internal_routes_are_rate_limited_before_authentication(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->withToken('invalid-internal-service-token')
                ->getJson('/api/v1/internal/merchants')
                ->assertUnauthorized();
        }

        $this->withToken('invalid-internal-service-token')
            ->getJson('/api/v1/internal/merchants')
            ->assertStatus(429);
    }

    public function test_service_can_provision_a_merchant_without_issuing_an_api_key(): void
    {
        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.merchant_code', 'udev-salon')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.provisioning_status', InternalMerchantProvisioningRequest::StatusProvisioned)
            ->assertJsonPath('data.credential_status', 'bootstrap_required')
            ->assertJsonPath('data.dana_configuration_status', 'required')
            ->assertJsonMissingPath('data.api_key')
            ->assertJsonMissingPath('data.contact_email')
            ->assertDontSee(self::ServiceToken);

        $this->assertDatabaseHas('merchants', [
            'code' => 'udev-salon',
            'name' => 'UDEV Salon',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('internal_merchant_provisioning_requests', [
            'service_id' => 'udev-billing',
            'idempotency_key' => 'provision-udev-salon-001',
            'environment' => 'sandbox',
        ]);
        $this->assertDatabaseHas('merchant_provisioning_audits', [
            'service_id' => 'udev-billing',
            'operation' => 'merchant.provisioned',
        ]);
        $this->assertDatabaseCount('merchant_api_keys', 0);
        $this->assertDatabaseCount('merchant_payment_configs', 0);

        $audit = MerchantProvisioningAudit::query()->sole();
        $this->assertSame([
            'environment' => 'sandbox',
            'credential_status' => 'bootstrap_required',
            'dana_configuration_status' => 'required',
        ], $audit->safe_details);
        $this->assertArrayNotHasKey('email', $audit->safe_details);
    }

    public function test_same_idempotency_key_and_payload_returns_the_same_merchant(): void
    {
        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertCreated();

        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.merchant_code', 'udev-salon');

        $this->assertDatabaseCount('merchants', 1);
        $this->assertDatabaseCount('internal_merchant_provisioning_requests', 1);
        $this->assertDatabaseCount('merchant_provisioning_audits', 1);
    }

    public function test_database_idempotency_constraint_preserves_one_record_when_requests_collide(): void
    {
        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertCreated();

        $request = InternalMerchantProvisioningRequest::query()->sole();

        try {
            InternalMerchantProvisioningRequest::query()->create([
                'service_id' => $request->service_id,
                'idempotency_key' => $request->idempotency_key,
                'request_fingerprint' => $request->request_fingerprint,
                'merchant_id' => $request->merchant_id,
                'environment' => $request->environment,
                'status' => InternalMerchantProvisioningRequest::StatusProvisioned,
            ]);
            $this->fail('Constraint idempotensi seharusnya menolak request yang berkompetisi.');
        } catch (QueryException) {
            $this->assertDatabaseCount('internal_merchant_provisioning_requests', 1);
            $this->assertDatabaseCount('merchants', 1);
            $this->assertDatabaseCount('merchant_provisioning_audits', 1);
        }
    }

    public function test_same_idempotency_key_with_a_different_payload_is_rejected(): void
    {
        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertCreated();

        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload(['name' => 'Nama Berbeda']))
            ->assertConflict();

        $this->assertDatabaseCount('merchants', 1);
    }

    public function test_duplicate_merchant_code_is_rejected_without_creating_a_partial_request(): void
    {
        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertCreated();

        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload([
                'idempotency_key' => 'provision-udev-salon-002',
            ]))
            ->assertConflict();

        $this->assertDatabaseCount('merchants', 1);
        $this->assertDatabaseCount('internal_merchant_provisioning_requests', 1);
    }

    public function test_internal_service_can_only_read_its_own_provisioned_merchant_metadata(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'bukan-udev']);
        $this->withToken(self::ServiceToken)
            ->postJson('/api/v1/internal/merchants', $this->payload())
            ->assertCreated();
        $provisionedMerchant = Merchant::query()->where('code', 'udev-salon')->sole();

        $this->withToken(self::ServiceToken)
            ->getJson('/api/v1/internal/merchants')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.merchant_id', $provisionedMerchant->id)
            ->assertJsonMissingPath('data.0.contact_email');

        $this->withToken(self::ServiceToken)
            ->getJson("/api/v1/internal/merchants/{$provisionedMerchant->id}")
            ->assertOk()
            ->assertJsonPath('data.merchant_code', 'udev-salon');
        $this->withToken(self::ServiceToken)
            ->getJson("/api/v1/internal/merchants/{$merchant->id}")
            ->assertNotFound();
    }

    public function test_existing_console_command_still_provisions_a_merchant_and_initial_api_key(): void
    {
        $exitCode = Artisan::call('merchant:provision command-merchant "Command Merchant" --email=command@example.test --key-name=command-key');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('merchants', ['code' => 'command-merchant', 'name' => 'Command Merchant']);
        $this->assertDatabaseCount('merchant_api_keys', 1);
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'udev-salon',
            'name' => 'UDEV Salon',
            'email' => 'billing@udev.test',
            'environment' => 'sandbox',
            'idempotency_key' => 'provision-udev-salon-001',
            ...$overrides,
        ];
    }
}
