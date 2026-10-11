<?php

namespace App\Services;

class DanaCredentials
{
    public function __construct(
        public string $partnerId,
        public string $merchantId,
        public string $privateKey,
        public string $danaPublicKey,
        public string $origin,
        public string $channelId,
        public string $notificationUrl,
        public string $returnUrl,
        public string $mcc,
    ) {}
}
