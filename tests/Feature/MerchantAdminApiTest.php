<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_access_merchant_admin_endpoints(): void
    {
        $merchant = Merchant::factory()->create();

        $this->getJson('/api/v1/admin/merchants')->assertUnauthorized();
        $this->getJson("/api/v1/admin/merchants/{$merchant->id}")->assertUnauthorized();
    }

    public function test_authenticated_administrator_can_filter_and_view_safe_merchant_data(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $matchingMerchant = Merchant::factory()->create([
            'name' => 'Aplikasi Uji',
            'code' => 'aplikasi-uji',
            'status' => 'active',
        ]);
        Payment::factory()->count(2)->for($matchingMerchant)->create();
        Merchant::factory()->create(['name' => 'Merchant Ditangguhkan', 'status' => 'suspended']);

        $this->getJson('/api/v1/admin/merchants?search=aplikasi&status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingMerchant->id)
            ->assertJsonPath('data.0.total_transactions', 2)
            ->assertJsonMissingPath('data.0.api_keys')
            ->assertJsonMissingPath('data.0.payment_configs');

        $this->getJson("/api/v1/admin/merchants/{$matchingMerchant->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'aplikasi-uji')
            ->assertJsonPath('data.total_transactions', 2)
            ->assertJsonMissingPath('data.api_keys')
            ->assertJsonMissingPath('data.payment_configs');
    }
}
