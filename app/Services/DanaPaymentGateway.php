<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use LogicException;

class DanaPaymentGateway implements PaymentGatewayInterface
{
    public function startCheckout(Payment $payment, MerchantPaymentConfig $config): void
    {
        throw new LogicException('Integrasi checkout DANA belum dikonfigurasi.');
    }
}
