<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use App\Models\Payment;

interface PaymentGatewayInterface
{
    public function startCheckout(Payment $payment, MerchantPaymentConfig $config): void;
}
