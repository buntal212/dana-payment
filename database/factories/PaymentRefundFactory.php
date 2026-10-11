<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRefund>
 */
class PaymentRefundFactory extends Factory
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
            'payment_id' => Payment::factory(),
            'merchant_refund_id' => fake()->unique()->bothify('refund-########'),
            'amount_minor' => fake()->numberBetween(1_000, 10_000_000),
            'currency' => 'IDR',
            'status' => 'pending',
        ];
    }
}
