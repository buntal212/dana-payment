<?php

namespace App\Services;

use App\Models\InternalMerchantManagementRequest;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantManagementAudit;
use App\Models\MerchantPaymentConfig;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class InternalMerchantManagementService
{
    private const string ProtectedMerchantCode = 'larissama';

    private const int ApiKeyUsageGracePeriodMinutes = 15;

    public function __construct(private DanaKeyMaterialNormalizer $keyMaterialNormalizer) {}

    /**
     * @return array<string, mixed>
     */
    public function readiness(Merchant $merchant, string $serviceId): array
    {
        $this->assertServiceOwnsMerchant($merchant, $serviceId);

        $config = $merchant->paymentConfigs()
            ->where('provider', 'dana')
            ->where('environment', 'sandbox')
            ->first();
        $configuration = $this->configurationStatus($config);
        $activeApiKeyCount = $merchant->apiKeys()->usable()->count();
        $notReadyComponents = [];

        if ($merchant->status !== 'active') {
            $notReadyComponents[] = 'merchant_active';
        }

        if ($activeApiKeyCount === 0) {
            $notReadyComponents[] = 'active_api_key';
        }

        foreach ($configuration['not_ready_components'] as $component) {
            $notReadyComponents[] = $component;
        }

        return [
            'merchant_code' => $merchant->code,
            'merchant_status' => $merchant->status,
            'environment' => 'sandbox',
            'active_api_key_count' => $activeApiKeyCount,
            'dana_configuration' => $this->publicConfigurationStatus($configuration),
            'readiness_status' => $notReadyComponents === [] ? 'ready' : 'not_ready',
            'not_ready_components' => $notReadyComponents,
        ];
    }

    /**
     * Mengembalikan metadata konfigurasi Sandbox yang aman untuk mengisi ulang
     * formulir. Nilai secret tidak pernah keluar dari Gateway.
     *
     * @return array<string, mixed>
     */
    public function danaConfiguration(Merchant $merchant, string $serviceId): array
    {
        $this->assertServiceOwnsMerchant($merchant, $serviceId);
        $this->assertMerchantConfigurationCanBeRead($merchant);

        $config = $merchant->paymentConfigs()
            ->where('provider', 'dana')
            ->where('environment', 'sandbox')
            ->first();
        $status = $this->configurationStatus($config);

        if ($config === null) {
            return [
                'merchant_code' => $merchant->code,
                'environment' => 'sandbox',
                'configuration_exists' => false,
                'dana_configuration' => $this->publicConfigurationStatus($status),
                'configuration' => null,
            ];
        }

        $credentials = is_array($config->encrypted_credentials) ? $config->encrypted_credentials : [];

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'configuration_exists' => true,
            'dana_configuration' => $this->publicConfigurationStatus($status),
            'configuration' => [
                'partner_id' => $config->partner_id,
                'merchant_id' => $credentials['merchant_id'] ?? null,
                'origin' => $credentials['origin'] ?? null,
                'channel_id' => $credentials['channel_id'] ?? null,
                'external_store_id' => $credentials['external_store_id'] ?? null,
                'sub_merchant_id' => $credentials['sub_merchant_id'] ?? null,
                'notification_url' => $credentials['notification_url'] ?? null,
                'callback_url' => $config->callback_url,
                'mcc' => $credentials['mcc'] ?? null,
                'enabled' => $config->enabled,
                'private_key_configured' => $this->credentialIsConfigured($credentials, 'private_key'),
                'dana_webhook_public_key_configured' => $this->credentialIsConfigured($credentials, 'dana_webhook_public_key'),
            ],
        ];
    }

    /**
     * @param  array{environment: string, idempotency_key: string, partner_id: string, merchant_id: string, private_key: string, dana_webhook_public_key: string, origin: string, channel_id: string, external_store_id?: string|null, sub_merchant_id?: string|null, notification_url: string, callback_url: string, mcc: string, enabled: bool}  $attributes
     * @return array{result: array<string, mixed>, was_idempotent_replay: bool}
     */
    public function configureDanaSandbox(Merchant $merchant, array $attributes, string $serviceId): array
    {
        $attributes = $this->normalizeKeyMaterial($attributes);
        $fingerprint = $this->fingerprint($attributes, ['idempotency_key']);

        return DB::transaction(function () use ($merchant, $attributes, $serviceId, $fingerprint): array {
            $lockedMerchant = Merchant::query()->lockForUpdate()->findOrFail($merchant->getKey());
            $this->assertServiceOwnsMerchant($lockedMerchant, $serviceId);

            $existingRequest = $this->existingRequest($lockedMerchant, $serviceId, 'merchant.dana_configured', $attributes['idempotency_key']);
            if ($existingRequest !== null) {
                return $this->sameRequestOrConflict($existingRequest, $fingerprint);
            }

            $this->assertMerchantCanBeChanged($lockedMerchant);
            $config = MerchantPaymentConfig::query()
                ->where('merchant_id', $lockedMerchant->getKey())
                ->where('provider', 'dana')
                ->where('environment', 'sandbox')
                ->lockForUpdate()
                ->first();

            if ($config === null) {
                $config = new MerchantPaymentConfig([
                    'merchant_id' => $lockedMerchant->getKey(),
                    'provider' => 'dana',
                    'environment' => 'sandbox',
                ]);
            }

            $existingCredentials = is_array($config->encrypted_credentials) ? $config->encrypted_credentials : [];
            $privateKey = $this->credentialForUpdate($attributes, $existingCredentials, 'private_key');
            $webhookPublicKey = $this->credentialForUpdate($attributes, $existingCredentials, 'dana_webhook_public_key');
            if ($privateKey === null || $webhookPublicKey === null) {
                throw new UnprocessableEntityHttpException('Private key dan DANA webhook public key wajib tersedia sebelum konfigurasi disimpan.');
            }

            $config->fill([
                'partner_id' => $attributes['partner_id'],
                'callback_url' => $attributes['callback_url'],
                'enabled' => $attributes['enabled'],
                'encrypted_credentials' => [
                    'merchant_id' => $attributes['merchant_id'],
                    'private_key' => $privateKey,
                    'dana_webhook_public_key' => $webhookPublicKey,
                    'origin' => $attributes['origin'],
                    'channel_id' => $attributes['channel_id'],
                    'external_store_id' => $this->optionalCredentialForUpdate($attributes, $existingCredentials, 'external_store_id'),
                    'sub_merchant_id' => $this->optionalCredentialForUpdate($attributes, $existingCredentials, 'sub_merchant_id'),
                    'notification_url' => $attributes['notification_url'],
                    'mcc' => $attributes['mcc'],
                ],
            ])->save();

            $safeResult = [
                'merchant_code' => $lockedMerchant->code,
                'environment' => 'sandbox',
                'dana_configuration' => $this->publicConfigurationStatus($this->configurationStatus($config)),
            ];
            $this->recordRequestAndAudit(
                merchant: $lockedMerchant,
                serviceId: $serviceId,
                operation: 'merchant.dana_configured',
                idempotencyKey: $attributes['idempotency_key'],
                fingerprint: $fingerprint,
                safeResult: $safeResult,
                safeDetails: [
                    'environment' => 'sandbox',
                    'enabled' => $config->enabled,
                    'configuration_ready' => $safeResult['dana_configuration']['ready'],
                ],
            );

            return ['result' => $safeResult, 'was_idempotent_replay' => false];
        });
    }

    /**
     * @return list<MerchantApiKey>
     */
    public function apiKeys(Merchant $merchant, string $serviceId): array
    {
        $this->assertServiceOwnsMerchant($merchant, $serviceId);

        return $merchant->apiKeys()->orderByDesc('id')->get()->all();
    }

    /**
     * @param  array{idempotency_key: string, confirm_revoke: bool}  $attributes
     * @return array{result: array<string, mixed>, was_idempotent_replay: bool}
     */
    public function revokeApiKey(Merchant $merchant, MerchantApiKey $apiKey, array $attributes, string $serviceId): array
    {
        $fingerprint = $this->fingerprint([
            'api_key_id' => $apiKey->getKey(),
            ...$attributes,
        ], ['idempotency_key']);

        return DB::transaction(function () use ($merchant, $apiKey, $attributes, $serviceId, $fingerprint): array {
            $lockedMerchant = Merchant::query()->lockForUpdate()->findOrFail($merchant->getKey());
            $this->assertServiceOwnsMerchant($lockedMerchant, $serviceId);

            $existingRequest = $this->existingRequest($lockedMerchant, $serviceId, 'merchant.api_key_revoked', $attributes['idempotency_key']);
            if ($existingRequest !== null) {
                return $this->sameRequestOrConflict($existingRequest, $fingerprint);
            }

            $this->assertMerchantCanBeChanged($lockedMerchant);
            $lockedApiKey = MerchantApiKey::query()
                ->where('merchant_id', $lockedMerchant->getKey())
                ->lockForUpdate()
                ->find($apiKey->getKey());

            if ($lockedApiKey === null) {
                $this->merchantNotFound($lockedMerchant);
            }

            if (! $lockedApiKey->is_active || $lockedApiKey->revoked_at !== null || ($lockedApiKey->expires_at !== null && $lockedApiKey->expires_at->isPast())) {
                throw new UnprocessableEntityHttpException('API key tidak lagi aktif.');
            }

            if ($lockedApiKey->last_used_at?->greaterThan(now()->subMinutes(self::ApiKeyUsageGracePeriodMinutes))) {
                throw new ConflictHttpException('API key masih digunakan dan belum melewati masa aman pencabutan.');
            }

            $remainingUsableKeys = MerchantApiKey::query()
                ->usable()
                ->where('merchant_id', $lockedMerchant->getKey())
                ->whereKeyNot($lockedApiKey->getKey())
                ->count();

            if ($remainingUsableKeys === 0) {
                throw new ConflictHttpException('API key aktif terakhir tidak dapat dicabut.');
            }

            $lockedApiKey->forceFill(['revoked_at' => now()])->save();
            $safeResult = [
                'id' => $lockedApiKey->getKey(),
                'key_prefix' => $lockedApiKey->key_prefix,
                'revoked_at' => $lockedApiKey->revoked_at?->toIso8601String(),
            ];
            $this->recordRequestAndAudit(
                merchant: $lockedMerchant,
                serviceId: $serviceId,
                operation: 'merchant.api_key_revoked',
                idempotencyKey: $attributes['idempotency_key'],
                fingerprint: $fingerprint,
                safeResult: $safeResult,
                safeDetails: ['api_key_id' => $lockedApiKey->getKey(), 'key_prefix' => $lockedApiKey->key_prefix],
            );

            return ['result' => $safeResult, 'was_idempotent_replay' => false];
        });
    }

    private function assertServiceOwnsMerchant(Merchant $merchant, string $serviceId): void
    {
        if (! $merchant->internalProvisioningRequests()->where('service_id', $serviceId)->exists()) {
            $this->merchantNotFound($merchant);
        }
    }

    private function assertMerchantCanBeChanged(Merchant $merchant): void
    {
        if ($merchant->code === self::ProtectedMerchantCode) {
            throw new AccessDeniedHttpException('Merchant ini tidak dapat diubah melalui layanan internal.');
        }

        if ($merchant->status !== 'active') {
            throw new UnprocessableEntityHttpException('Merchant tidak aktif.');
        }
    }

    private function assertMerchantConfigurationCanBeRead(Merchant $merchant): void
    {
        if ($merchant->code === self::ProtectedMerchantCode) {
            throw new AccessDeniedHttpException('Merchant ini tidak dapat diakses melalui layanan internal.');
        }
    }

    private function merchantNotFound(Merchant $merchant): never
    {
        throw (new ModelNotFoundException)->setModel(Merchant::class, [$merchant->getKey()]);
    }

    private function existingRequest(Merchant $merchant, string $serviceId, string $operation, string $idempotencyKey): ?InternalMerchantManagementRequest
    {
        return $merchant->internalManagementRequests()
            ->where('service_id', $serviceId)
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array{result: array<string, mixed>, was_idempotent_replay: bool}
     */
    private function sameRequestOrConflict(InternalMerchantManagementRequest $request, string $fingerprint): array
    {
        if (! hash_equals($request->request_fingerprint, $fingerprint)) {
            throw new ConflictHttpException('Idempotency key sudah digunakan untuk payload yang berbeda.');
        }

        return ['result' => $request->safe_result ?? [], 'was_idempotent_replay' => true];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $excludedKeys
     */
    private function fingerprint(array $attributes, array $excludedKeys): string
    {
        foreach ($excludedKeys as $excludedKey) {
            unset($attributes[$excludedKey]);
        }

        ksort($attributes);

        return hash_hmac('sha256', json_encode($attributes, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $safeResult
     * @param  array<string, mixed>  $safeDetails
     */
    private function recordRequestAndAudit(Merchant $merchant, string $serviceId, string $operation, string $idempotencyKey, string $fingerprint, array $safeResult, array $safeDetails): void
    {
        InternalMerchantManagementRequest::query()->create([
            'merchant_id' => $merchant->getKey(),
            'service_id' => $serviceId,
            'operation' => $operation,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'status' => InternalMerchantManagementRequest::StatusCompleted,
            'safe_result' => $safeResult,
        ]);
        MerchantManagementAudit::query()->create([
            'merchant_id' => $merchant->getKey(),
            'service_id' => $serviceId,
            'operation' => $operation,
            'request_fingerprint' => $fingerprint,
            'safe_details' => $safeDetails,
        ]);
    }

    /**
     * @return array{available: bool, enabled: bool, ready: bool, not_ready_components: list<string>}
     */
    private function configurationStatus(?MerchantPaymentConfig $config): array
    {
        if ($config === null) {
            return [
                'available' => false,
                'enabled' => false,
                'ready' => false,
                'not_ready_components' => ['dana_configuration'],
            ];
        }

        $notReadyComponents = [];
        if (! $config->enabled) {
            $notReadyComponents[] = 'dana_configuration_enabled';
        }
        if (! is_string($config->partner_id) || $config->partner_id === '') {
            $notReadyComponents[] = 'dana_partner_id';
        }
        if (! $this->isHttpsUrl($config->callback_url)) {
            $notReadyComponents[] = 'dana_callback_url';
        }

        $credentials = is_array($config->encrypted_credentials) ? $config->encrypted_credentials : [];
        foreach (['merchant_id', 'private_key', 'dana_webhook_public_key', 'origin', 'channel_id', 'notification_url', 'mcc'] as $field) {
            if (! is_string($credentials[$field] ?? null) || $credentials[$field] === '') {
                $notReadyComponents[] = 'dana_'.$field;
            }
        }

        if (! $this->isHttpsUrl($credentials['origin'] ?? null)) {
            $notReadyComponents[] = 'dana_origin';
        }
        if (! $this->isHttpsUrl($credentials['notification_url'] ?? null)) {
            $notReadyComponents[] = 'dana_notification_url';
        }

        return [
            'available' => true,
            'enabled' => $config->enabled,
            'ready' => $notReadyComponents === [],
            'not_ready_components' => array_values(array_unique($notReadyComponents)),
        ];
    }

    /**
     * @param  array{available: bool, enabled: bool, ready: bool, not_ready_components: list<string>}  $configuration
     * @return array{available: bool, enabled: bool, ready: bool}
     */
    private function publicConfigurationStatus(array $configuration): array
    {
        return [
            'available' => $configuration['available'],
            'enabled' => $configuration['enabled'],
            'ready' => $configuration['ready'],
        ];
    }

    private function isHttpsUrl(mixed $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && str_starts_with(strtolower($value), 'https://');
    }

    /** @param array<string, mixed> $credentials */
    private function credentialIsConfigured(array $credentials, string $field): bool
    {
        return is_string($credentials[$field] ?? null) && $credentials[$field] !== '';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $existingCredentials
     */
    private function credentialForUpdate(array $attributes, array $existingCredentials, string $field): ?string
    {
        $value = $attributes[$field] ?? $existingCredentials[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param array<string, mixed> $attributes @param array<string, mixed> $existingCredentials */
    private function optionalCredentialForUpdate(array $attributes, array $existingCredentials, string $field): ?string
    {
        if (array_key_exists($field, $attributes)) {
            $value = $attributes[$field];

            return is_string($value) && trim($value) !== '' ? $value : null;
        }

        return $this->credentialForUpdate($attributes, $existingCredentials, $field);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function normalizeKeyMaterial(array $attributes): array
    {
        try {
            foreach (['private_key' => 'normalizePrivateKey', 'dana_webhook_public_key' => 'normalizePublicKey'] as $field => $method) {
                if (is_string($attributes[$field] ?? null) && trim($attributes[$field]) !== '') {
                    $attributes[$field] = $this->keyMaterialNormalizer->{$method}($attributes[$field]);
                }
            }
        } catch (DanaKeyMaterialException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage());
        }

        return $attributes;
    }
}
