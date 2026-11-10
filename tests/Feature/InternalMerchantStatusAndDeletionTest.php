<?php

namespace Tests\Feature;

use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\MerchantProvisioningAudit;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class InternalMerchantStatusAndDeletionTest extends TestCase
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

    public function test_patch_status_requires_internal_service_token(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'test-merchant']);

        $this->patchJson("/api/v1/internal/merchants/{$merchant->id}/status", ['status' => 'suspended'])
            ->assertUnauthorized();
    }

    public function test_patch_status_rejects_modifying_udev_central(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'udev-central']);

        $this->withToken(self::ServiceToken)
            ->patchJson("/api/v1/internal/merchants/{$merchant->id}/status", ['status' => 'suspended'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Merchant udev-central adalah merchant sistem pusat dan tidak dapat diubah statusnya.');
    }

    public function test_patch_status_validates_status_value(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'custom-merchant']);

        $this->withToken(self::ServiceToken)
            ->patchJson("/api/v1/internal/merchants/{$merchant->id}/status", ['status' => 'invalid_status'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('status');
    }

    public function test_patch_status_blocks_deactivating_merchant_with_pending_payments(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'custom-merchant', 'status' => 'active']);
        Payment::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => 'pending',
        ]);

        $this->withToken(self::ServiceToken)
            ->patchJson("/api/v1/internal/merchants/{$merchant->id}/status", ['status' => 'suspended'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('status', 'error')
            ->assertJsonFragment(['message' => 'Merchant memiliki 1 transaksi pending dan tidak dapat dinonaktifkan.']);

        $this->assertSame('active', $merchant->fresh()->status);
    }

    public function test_patch_status_successfully_updates_and_creates_audit(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'custom-merchant', 'status' => 'active']);
        InternalMerchantProvisioningRequest::factory()->create([
            'merchant_id' => $merchant->id,
            'service_id' => 'udev-billing',
            'environment' => 'sandbox',
            'status' => 'provisioned',
        ]);

        $response = $this->withToken(self::ServiceToken)
            ->patchJson("/api/v1/internal/merchants/{$merchant->id}/status", ['status' => 'suspended'])
            ->assertOk();

        $this->assertSame('suspended', $merchant->fresh()->status);
        $this->assertSame('suspended', $response->json('data.merchant_status'));

        $audit = MerchantProvisioningAudit::query()
            ->where('merchant_id', $merchant->id)
            ->where('operation', 'merchant.deactivated')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('suspended', $audit->safe_details['status']);
    }

    public function test_delete_requires_internal_service_token(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'custom-merchant']);

        $this->deleteJson("/api/v1/internal/merchants/{$merchant->id}")
            ->assertUnauthorized();
    }

    public function test_delete_rejects_udev_central(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'udev-central']);

        $this->withToken(self::ServiceToken)
            ->deleteJson("/api/v1/internal/merchants/{$merchant->id}")
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Merchant udev-central adalah merchant sistem pusat dan tidak dapat dihapus.');
    }

    public function test_delete_blocks_merchant_with_pending_payments(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'custom-merchant']);
        Payment::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => 'pending',
        ]);

        $this->withToken(self::ServiceToken)
            ->deleteJson("/api/v1/internal/merchants/{$merchant->id}")
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('status', 'error')
            ->assertJsonFragment(['message' => 'Merchant memiliki 1 transaksi pending dan tidak dapat dihapus.']);

        $this->assertNull($merchant->fresh()->deleted_at);
    }

    public function test_delete_blocks_merchant_with_payment_history_and_recommends_deactivation(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'custom-merchant']);
        Payment::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => 'paid',
        ]);

        $this->withToken(self::ServiceToken)
            ->deleteJson("/api/v1/internal/merchants/{$merchant->id}")
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('status', 'error')
            ->assertJsonFragment(['message' => 'Merchant memiliki riwayat 1 transaksi pembayaran dan tidak dapat dihapus. Silakan nonaktifkan merchant sebagai gantinya.']);

        $this->assertNull($merchant->fresh()->deleted_at);
    }

    public function test_delete_successfully_soft_deletes_merchant_without_history(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'unused-merchant']);

        $this->withToken(self::ServiceToken)
            ->deleteJson("/api/v1/internal/merchants/{$merchant->id}")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertNotNull($merchant->fresh()->deleted_at);

        $audit = MerchantProvisioningAudit::query()
            ->where('merchant_id', $merchant->id)
            ->where('operation', 'merchant.deleted')
            ->first();
        $this->assertNotNull($audit);
    }

    public function test_dynamic_primary_merchant_protection_protects_new_primary_and_allows_deactivating_udev_central(): void
    {
        $primary = Merchant::factory()->create(['code' => 'MRC-0002', 'status' => 'active']);
        $oldCentral = Merchant::factory()->create(['code' => 'udev-central', 'status' => 'active']);

        // MRC-0002 is primary, should be protected
        $this->withToken(self::ServiceToken)
            ->withHeader('X-Primary-Merchant-Code', 'MRC-0002')
            ->patchJson("/api/v1/internal/merchants/{$primary->id}/status", ['status' => 'suspended'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Merchant MRC-0002 adalah merchant sistem pusat dan tidak dapat diubah statusnya.');

        // udev-central is no longer primary, can be suspended if no pending payments
        $this->withToken(self::ServiceToken)
            ->withHeader('X-Primary-Merchant-Code', 'MRC-0002')
            ->patchJson("/api/v1/internal/merchants/{$oldCentral->id}/status", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('suspended', $oldCentral->fresh()->status);
    }
}
