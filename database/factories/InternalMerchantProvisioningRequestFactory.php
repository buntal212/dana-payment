<?php

namespace Database\Factories;

use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InternalMerchantProvisioningRequest>
 */
class InternalMerchantProvisioningRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => 'udev-billing',
            'idempotency_key' => fake()->unique()->uuid(),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'merchant_id' => Merchant::factory(),
            'environment' => 'sandbox',
            'status' => InternalMerchantProvisioningRequest::StatusProvisioned,
        ];
    }
}
