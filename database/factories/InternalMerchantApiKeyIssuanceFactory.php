<?php

namespace Database\Factories;

use App\Models\InternalMerchantApiKeyIssuance;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternalMerchantApiKeyIssuance> */
class InternalMerchantApiKeyIssuanceFactory extends Factory
{
    public function definition(): array
    {
        return ['merchant_id' => Merchant::factory(), 'service_id' => 'testing-service', 'idempotency_key' => fake()->uuid(), 'request_fingerprint' => hash('sha256', fake()->uuid()), 'name' => 'UDEV Sandbox', 'key_prefix' => 'dpk_test', 'key_hash' => hash('sha256', fake()->uuid()), 'abilities' => ['payments:*'], 'expires_at' => null, 'status' => InternalMerchantApiKeyIssuance::StatusPending, 'pending_expires_at' => now()->addMinutes(10)];
    }
}
