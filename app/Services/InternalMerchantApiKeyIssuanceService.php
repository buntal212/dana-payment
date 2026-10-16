<?php

namespace App\Services;

use App\Models\InternalMerchantApiKeyIssuance;
use App\Models\InternalMerchantManagementRequest;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\MerchantManagementAudit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class InternalMerchantApiKeyIssuanceService
{
    private const string ProtectedMerchantCode = 'larissama';

    private const string OperationIssued = 'merchant.api_key_issued';

    private const string OperationConfirmed = 'merchant.api_key_issue_confirmed';

    private const string OperationCancelled = 'merchant.api_key_issue_cancelled';

    public function __construct(private MerchantApiKeyService $merchantApiKeyService) {}

    /** @param array{environment: string, name: string, abilities: list<string>|null, expires_at: string|null, idempotency_key: string} $attributes */
    public function issue(Merchant $merchant, array $attributes, string $serviceId): array
    {
        $fingerprint = $this->fingerprint($attributes, ['idempotency_key']);

        try {
            return DB::transaction(function () use ($merchant, $attributes, $serviceId, $fingerprint): array {
                $lockedMerchant = Merchant::query()->lockForUpdate()->findOrFail($merchant->getKey());
                $this->assertIssuableMerchant($lockedMerchant, $serviceId);
                $existing = InternalMerchantApiKeyIssuance::query()->where('service_id', $serviceId)->where('idempotency_key', $attributes['idempotency_key'])->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->assertSameFingerprint($existing->request_fingerprint, $fingerprint);

                    return ['issuance' => $existing, 'api_key' => null, 'replayed' => true];
                }

                $this->expirePendingIssuances($lockedMerchant, $serviceId);
                if ($lockedMerchant->internalApiKeyIssuances()->where('status', InternalMerchantApiKeyIssuance::StatusPending)->exists()) {
                    throw new ConflictHttpException('Penerbitan API key sebelumnya masih menunggu konfirmasi penyimpanan secret.');
                }

                $apiKey = $this->merchantApiKeyService->generateApiKey();
                $reservedApiKey = MerchantApiKey::query()->create([
                    'merchant_id' => $lockedMerchant->getKey(),
                    'name' => $attributes['name'],
                    'key_prefix' => substr($apiKey, 0, 24),
                    'key_hash' => hash('sha256', $apiKey),
                    'abilities' => $attributes['abilities'] ?? null,
                    'expires_at' => ($attributes['expires_at'] ?? null) === null ? null : Carbon::parse($attributes['expires_at']),
                    'is_active' => false,
                ]);
                $issuance = InternalMerchantApiKeyIssuance::query()->create([
                    'merchant_id' => $lockedMerchant->getKey(), 'service_id' => $serviceId, 'idempotency_key' => $attributes['idempotency_key'],
                    'request_fingerprint' => $fingerprint, 'name' => $attributes['name'], 'key_prefix' => substr($apiKey, 0, 24),
                    'key_hash' => hash('sha256', $apiKey), 'abilities' => $attributes['abilities'] ?? null,
                    'expires_at' => ($attributes['expires_at'] ?? null) === null ? null : Carbon::parse($attributes['expires_at']),
                    'status' => InternalMerchantApiKeyIssuance::StatusPending, 'pending_expires_at' => now()->addMinutes($this->pendingTtlMinutes()),
                    'merchant_api_key_id' => $reservedApiKey->getKey(),
                ]);
                $this->audit($lockedMerchant, $serviceId, self::OperationIssued, $fingerprint, ['issuance_id' => $issuance->getKey(), 'merchant_api_key_id' => $reservedApiKey->getKey(), 'status' => $issuance->status, 'key_prefix' => $issuance->key_prefix]);

                return ['issuance' => $issuance, 'api_key' => $apiKey, 'replayed' => false];
            });
        } catch (QueryException $exception) {
            $existing = InternalMerchantApiKeyIssuance::query()->where('service_id', $serviceId)->where('idempotency_key', $attributes['idempotency_key'])->first();
            if ($existing !== null) {
                $this->assertSameFingerprint($existing->request_fingerprint, $fingerprint);

                return ['issuance' => $existing, 'api_key' => null, 'replayed' => true];
            }
            throw $exception;
        }
    }

    public function status(Merchant $merchant, InternalMerchantApiKeyIssuance $issuance, string $serviceId): InternalMerchantApiKeyIssuance
    {
        return DB::transaction(function () use ($merchant, $issuance, $serviceId): InternalMerchantApiKeyIssuance {
            $lockedMerchant = Merchant::query()->lockForUpdate()->findOrFail($merchant->getKey());
            $this->assertIssuableMerchant($lockedMerchant, $serviceId);
            $lockedIssuance = $this->lockedIssuance($lockedMerchant, $issuance);
            $this->expireIssuanceIfNeeded($lockedIssuance, $lockedMerchant, $serviceId);

            return $lockedIssuance->fresh();
        });
    }

    /** @param array{idempotency_key: string, confirm_storage: bool} $attributes */
    public function confirm(Merchant $merchant, InternalMerchantApiKeyIssuance $issuance, array $attributes, string $serviceId): array
    {
        return $this->mutate($merchant, $issuance, $attributes, $serviceId, self::OperationConfirmed, function (InternalMerchantApiKeyIssuance $lockedIssuance, Merchant $lockedMerchant): array {
            if ($lockedIssuance->status === InternalMerchantApiKeyIssuance::StatusConfirmed) {
                return $this->safeResult($lockedIssuance);
            }
            $this->assertPending($lockedIssuance);
            $reservedApiKey = $this->reservedApiKey($lockedMerchant, $lockedIssuance);
            if ($reservedApiKey->is_active) {
                throw new ConflictHttpException('Reservasi API key memiliki status tidak valid.');
            }

            $reservedApiKey->forceFill(['is_active' => true])->save();
            $lockedIssuance->forceFill(['status' => InternalMerchantApiKeyIssuance::StatusConfirmed, 'confirmed_at' => now()])->save();

            return $this->safeResult($lockedIssuance);
        });
    }

    /** @param array{idempotency_key: string, confirm_cancel: bool} $attributes */
    public function cancel(Merchant $merchant, InternalMerchantApiKeyIssuance $issuance, array $attributes, string $serviceId): array
    {
        return $this->mutate($merchant, $issuance, $attributes, $serviceId, self::OperationCancelled, function (InternalMerchantApiKeyIssuance $lockedIssuance, Merchant $lockedMerchant): array {
            if ($lockedIssuance->status === InternalMerchantApiKeyIssuance::StatusCancelled) {
                return $this->safeResult($lockedIssuance);
            }
            if ($lockedIssuance->status === InternalMerchantApiKeyIssuance::StatusConfirmed) {
                throw new ConflictHttpException('API key yang sudah dikonfirmasi tidak dapat dibatalkan melalui issuance.');
            }
            if ($lockedIssuance->status === InternalMerchantApiKeyIssuance::StatusExpired) {
                return $this->safeResult($lockedIssuance);
            }
            $this->deactivateReservedApiKey($lockedMerchant, $lockedIssuance);
            $lockedIssuance->forceFill(['status' => InternalMerchantApiKeyIssuance::StatusCancelled, 'cancelled_at' => now()])->save();

            return $this->safeResult($lockedIssuance);
        });
    }

    private function mutate(Merchant $merchant, InternalMerchantApiKeyIssuance $issuance, array $attributes, string $serviceId, string $operation, callable $mutation): array
    {
        $fingerprint = $this->fingerprint(['issuance_id' => $issuance->getKey(), ...$attributes], ['idempotency_key']);

        return DB::transaction(function () use ($merchant, $issuance, $attributes, $serviceId, $operation, $fingerprint, $mutation): array {
            $lockedMerchant = Merchant::query()->lockForUpdate()->findOrFail($merchant->getKey());
            $this->assertIssuableMerchant($lockedMerchant, $serviceId);
            $existing = InternalMerchantManagementRequest::query()->where('merchant_id', $lockedMerchant->getKey())->where('service_id', $serviceId)->where('operation', $operation)->where('idempotency_key', $attributes['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertSameFingerprint($existing->request_fingerprint, $fingerprint);

                return $existing->safe_result ?? [];
            }
            $lockedIssuance = $this->lockedIssuance($lockedMerchant, $issuance);
            $this->expireIssuanceIfNeeded($lockedIssuance, $lockedMerchant, $serviceId);
            $result = $mutation($lockedIssuance, $lockedMerchant);
            InternalMerchantManagementRequest::query()->create(['merchant_id' => $lockedMerchant->getKey(), 'service_id' => $serviceId, 'operation' => $operation, 'idempotency_key' => $attributes['idempotency_key'], 'request_fingerprint' => $fingerprint, 'status' => InternalMerchantManagementRequest::StatusCompleted, 'safe_result' => $result]);
            $this->audit($lockedMerchant, $serviceId, $operation, $fingerprint, ['issuance_id' => $lockedIssuance->getKey(), 'status' => $lockedIssuance->status]);

            return $result;
        });
    }

    private function assertIssuableMerchant(Merchant $merchant, string $serviceId): void
    {
        if (! $merchant->internalProvisioningRequests()->where('service_id', $serviceId)->where('environment', 'sandbox')->exists()) {
            throw (new ModelNotFoundException)->setModel(Merchant::class, [$merchant->getKey()]);
        }
        if ($merchant->code === self::ProtectedMerchantCode) {
            throw new ConflictHttpException('Merchant ini tidak dapat menerbitkan API key melalui layanan internal.');
        }
        if ($merchant->status !== 'active') {
            throw new UnprocessableEntityHttpException('Merchant tidak aktif.');
        }
    }

    private function lockedIssuance(Merchant $merchant, InternalMerchantApiKeyIssuance $issuance): InternalMerchantApiKeyIssuance
    {
        $locked = InternalMerchantApiKeyIssuance::query()->where('merchant_id', $merchant->getKey())->lockForUpdate()->find($issuance->getKey());
        if ($locked === null) {
            throw (new ModelNotFoundException)->setModel(InternalMerchantApiKeyIssuance::class, [$issuance->getKey()]);
        }

        return $locked;
    }

    private function expirePendingIssuances(Merchant $merchant, string $serviceId): void
    {
        $merchant->internalApiKeyIssuances()
            ->where('status', InternalMerchantApiKeyIssuance::StatusPending)
            ->where('pending_expires_at', '<=', now())
            ->lockForUpdate()
            ->get()
            ->each(function (InternalMerchantApiKeyIssuance $issuance) use ($merchant, $serviceId): void {
                $this->deactivateReservedApiKey($merchant, $issuance);
                $issuance->forceFill(['status' => InternalMerchantApiKeyIssuance::StatusExpired])->save();
                $this->audit($merchant, $serviceId, 'merchant.api_key_issue_expired', $issuance->request_fingerprint, ['issuance_id' => $issuance->getKey(), 'merchant_api_key_id' => $issuance->merchant_api_key_id, 'status' => InternalMerchantApiKeyIssuance::StatusExpired]);
            });
    }

    private function expireIssuanceIfNeeded(InternalMerchantApiKeyIssuance $issuance, Merchant $merchant, string $serviceId): void
    {
        if ($issuance->status === InternalMerchantApiKeyIssuance::StatusPending && $issuance->pending_expires_at->isPast()) {
            $this->deactivateReservedApiKey($merchant, $issuance);
            $issuance->update(['status' => InternalMerchantApiKeyIssuance::StatusExpired]);
            $this->audit($merchant, $serviceId, 'merchant.api_key_issue_expired', $issuance->request_fingerprint, ['issuance_id' => $issuance->getKey(), 'merchant_api_key_id' => $issuance->merchant_api_key_id, 'status' => InternalMerchantApiKeyIssuance::StatusExpired]);
        }
    }

    private function reservedApiKey(Merchant $merchant, InternalMerchantApiKeyIssuance $issuance): MerchantApiKey
    {
        $reservedApiKey = MerchantApiKey::query()
            ->where('merchant_id', $merchant->getKey())
            ->lockForUpdate()
            ->find($issuance->merchant_api_key_id);

        if ($reservedApiKey === null || ! hash_equals($issuance->key_hash, $reservedApiKey->key_hash)) {
            throw new ConflictHttpException('Reservasi API key tidak valid.');
        }

        return $reservedApiKey;
    }

    private function deactivateReservedApiKey(Merchant $merchant, InternalMerchantApiKeyIssuance $issuance): void
    {
        if ($issuance->merchant_api_key_id === null) {
            return;
        }

        MerchantApiKey::query()
            ->where('merchant_id', $merchant->getKey())
            ->whereKey($issuance->merchant_api_key_id)
            ->update(['is_active' => false]);
    }

    private function assertPending(InternalMerchantApiKeyIssuance $issuance): void
    {
        if ($issuance->status !== InternalMerchantApiKeyIssuance::StatusPending) {
            throw new UnprocessableEntityHttpException('Penerbitan API key tidak lagi menunggu konfirmasi.');
        }
    }

    private function pendingTtlMinutes(): int
    {
        return max(1, min(60, (int) config('services.udev_internal.api_key_issuance_pending_ttl_minutes', 10)));
    }

    private function assertSameFingerprint(string $actual, string $expected): void
    {
        if (! hash_equals($actual, $expected)) {
            throw new ConflictHttpException('Idempotency key sudah digunakan untuk payload yang berbeda.');
        }
    }

    private function fingerprint(array $attributes, array $excludedKeys): string
    {
        foreach ($excludedKeys as $excludedKey) {
            unset($attributes[$excludedKey]);
        } ksort($attributes);

        return hash_hmac('sha256', json_encode($attributes, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function safeResult(InternalMerchantApiKeyIssuance $issuance): array
    {
        return ['issuance_id' => $issuance->getKey(), 'status' => $issuance->status, 'key_prefix' => $issuance->key_prefix, 'pending_expires_at' => $issuance->pending_expires_at?->toIso8601String(), 'merchant_api_key_id' => $issuance->merchant_api_key_id];
    }

    private function audit(Merchant $merchant, string $serviceId, string $operation, string $fingerprint, array $safeDetails): void
    {
        MerchantManagementAudit::query()->create(['merchant_id' => $merchant->getKey(), 'service_id' => $serviceId, 'operation' => $operation, 'request_fingerprint' => $fingerprint, 'safe_details' => $safeDetails]);
    }
}
