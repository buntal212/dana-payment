<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use LogicException;

class DanaCredentialResolver
{
    public function resolve(MerchantPaymentConfig $config): DanaCredentials
    {
        $credentials = $config->encrypted_credentials;

        if (! $config->enabled || ! is_array($credentials)) {
            throw new LogicException('Konfigurasi DANA merchant belum aktif.');
        }

        foreach (['merchant_id', 'private_key', 'dana_webhook_public_key', 'origin', 'channel_id', 'notification_url', 'mcc'] as $field) {
            if (! is_string($credentials[$field] ?? null) || $credentials[$field] === '') {
                throw new LogicException('Konfigurasi DANA merchant tidak lengkap.');
            }
        }

        if (! is_string($config->partner_id) || $config->partner_id === '' || ! is_string($config->callback_url) || $config->callback_url === '') {
            throw new LogicException('Konfigurasi DANA merchant tidak lengkap.');
        }

        return new DanaCredentials(
            partnerId: $config->partner_id,
            merchantId: $credentials['merchant_id'],
            privateKey: $credentials['private_key'],
            danaPublicKey: $credentials['dana_webhook_public_key'],
            origin: $credentials['origin'],
            channelId: $credentials['channel_id'],
            notificationUrl: $credentials['notification_url'],
            returnUrl: $config->callback_url,
            mcc: $credentials['mcc'],
            externalStoreId: $this->optionalCredential($credentials, 'external_store_id'),
            subMerchantId: $this->optionalCredential($credentials, 'sub_merchant_id'),
        );
    }

    /** @param array<string, mixed> $credentials */
    private function optionalCredential(array $credentials, string $field): ?string
    {
        $value = $credentials[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
