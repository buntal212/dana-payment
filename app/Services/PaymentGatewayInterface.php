<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use App\Models\Payment;

interface PaymentGatewayInterface
{
    /**
     * @return array{provider_order_id: string, checkout_url: string|null, expires_at: \DateTimeInterface, payment_instruction: array<string, mixed>}
     */
    public function startCheckout(Payment $payment, MerchantPaymentConfig $config): array;
}
