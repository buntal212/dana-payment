<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentEvent>
 */
class PaymentEventFactory extends Factory
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
            'payment_id' => Payment::factory(),
            'event_type' => 'payment.created',
            'new_status' => 'pending',
            'source' => 'system',
            'safe_details' => [],
        ];
    }
}
