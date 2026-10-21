<?php

namespace Database\Factories;

use App\Models\DanaSandboxTestRun;
use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DanaSandboxTestRun> */
class DanaSandboxTestRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'payment_id' => Payment::factory(),
            'operation' => 'transaction_created',
            'environment' => 'sandbox',
            'status' => 'succeeded',
            'message' => 'Checkout Sandbox berhasil dibuat.',
            'safe_details' => ['checkout_available' => true],
        ];
    }
}
