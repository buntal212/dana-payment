<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'merchant_order_id' => fake()->unique()->bothify('order-########'),
            'provider' => 'dana',
            'environment' => 'sandbox',
            'amount_minor' => fake()->numberBetween(1_000, 10_000_000),
            'currency' => 'IDR',
            'status' => 'pending',
            'idempotency_key' => fake()->unique()->uuid(),
            'metadata' => ['reference' => fake()->uuid()],
        ];
    }
}
