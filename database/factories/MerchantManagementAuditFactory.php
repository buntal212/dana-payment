<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantManagementAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantManagementAudit>
 */
class MerchantManagementAuditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'service_id' => 'testing-service',
            'operation' => 'merchant.dana_configured',
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'safe_details' => ['environment' => 'sandbox'],
        ];
    }
}
