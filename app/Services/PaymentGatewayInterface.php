<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use App\Models\Payment;

interface PaymentGatewayInterface
{
    /** @return array{provider_order_id: string, checkout_url: string, expires_at: \DateTimeInterface} */
    public function startCheckout(Payment $payment, MerchantPaymentConfig $config): array;
}
