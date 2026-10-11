<?php

namespace Database\Factories;

use App\Models\PaymentWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentWebhook>
 */
class PaymentWebhookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'dana',
            'environment' => 'sandbox',
            'payload_sha256' => hash('sha256', fake()->uuid()),
            'signature_valid' => false,
            'processing_status' => 'received',
            'attempts' => 0,
        ];
    }
}
