<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantProvisioningAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantProvisioningAudit>
 */
class MerchantProvisioningAuditFactory extends Factory
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
            'service_id' => 'udev-billing',
            'operation' => 'merchant.provisioned',
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'safe_details' => ['environment' => 'sandbox'],
        ];
    }
}
