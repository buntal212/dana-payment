<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantPaymentConfig>
 */
class MerchantPaymentConfigFactory extends Factory
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
            'provider' => 'dana',
            'environment' => 'sandbox',
            'partner_id' => fake()->bothify('partner-####'),
            'encrypted_credentials' => ['client_id' => fake()->uuid()],
            'callback_url' => fake()->url(),
            'enabled' => false,
        ];
    }
}
