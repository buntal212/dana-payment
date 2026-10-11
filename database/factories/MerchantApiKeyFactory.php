<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantApiKey>
 */
class MerchantApiKeyFactory extends Factory
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
            'name' => 'testing',
            'key_prefix' => fake()->unique()->lexify('dpk_????????????????'),
            'key_hash' => hash('sha256', fake()->uuid()),
            'abilities' => ['payments:*'],
            'expires_at' => null,
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }
}
