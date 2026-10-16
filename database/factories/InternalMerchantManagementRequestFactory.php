<?php

namespace Database\Factories;

use App\Models\InternalMerchantManagementRequest;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InternalMerchantManagementRequest>
 */
class InternalMerchantManagementRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => 'testing-service',
            'operation' => 'merchant.dana_configured',
            'idempotency_key' => fake()->unique()->uuid(),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'merchant_id' => Merchant::factory(),
            'status' => InternalMerchantManagementRequest::StatusCompleted,
            'safe_result' => ['environment' => 'sandbox'],
        ];
    }
}
