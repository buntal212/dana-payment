<?php

namespace App\Services;

use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\MerchantProvisioningAudit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MerchantProvisioningService
{
    /**
     * @param  array{code: string, name: string, email: string|null}  $attributes
     */
    public function createMerchant(array $attributes): Merchant
    {
        return Merchant::query()->create([
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'contact_email' => $attributes['email'],
            'status' => 'active',
        ]);
    }

    /**
     * @param  array{code: string, name: string, email: string|null, environment: string, idempotency_key: string}  $attributes
     */
    public function provisionForInternalService(array $attributes, string $serviceId): InternalMerchantProvisioningRequest
    {
        $fingerprint = $this->fingerprint($attributes);

        try {
            return DB::transaction(function () use ($attributes, $serviceId, $fingerprint): InternalMerchantProvisioningRequest {
                $existing = InternalMerchantProvisioningRequest::query()
                    ->where('service_id', $serviceId)
                    ->where('idempotency_key', $attributes['idempotency_key'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->sameRequestOrConflict($existing, $fingerprint);
                }

                $mappedRequest = $this->matchingProvisionedMerchant($attributes, $serviceId, $fingerprint);
                if ($mappedRequest !== null) {
                    return $mappedRequest;
                }

                $merchant = $this->createMerchant($attributes);
                $request = InternalMerchantProvisioningRequest::query()->create([
                    'service_id' => $serviceId,
                    'idempotency_key' => $attributes['idempotency_key'],
                    'request_fingerprint' => $fingerprint,
                    'merchant_id' => $merchant->getKey(),
                    'environment' => $attributes['environment'],
                    'status' => InternalMerchantProvisioningRequest::StatusProvisioned,
                ]);
                MerchantProvisioningAudit::query()->create([
                    'merchant_id' => $merchant->getKey(),
                    'service_id' => $serviceId,
                    'operation' => 'merchant.provisioned',
                    'request_fingerprint' => $fingerprint,
                    'safe_details' => [
                        'environment' => $attributes['environment'],
                        'credential_status' => 'bootstrap_required',
                        'dana_configuration_status' => 'required',
                    ],
                ]);

                return $request->load('merchant');
            });
        } catch (QueryException $exception) {
            return $this->resolveConcurrentRequest($attributes, $serviceId, $fingerprint, $exception);
        }
    }

    /**
     * @param  array{code: string, name: string, email: string|null, key_name: string, environment: string, idempotency_key: string}  $attributes
     */
    private function fingerprint(array $attributes): string
    {
        return hash('sha256', json_encode([
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'environment' => $attributes['environment'],
        ], JSON_THROW_ON_ERROR));
    }

    private function sameRequestOrConflict(InternalMerchantProvisioningRequest $request, string $fingerprint): InternalMerchantProvisioningRequest
    {
        if (! hash_equals($request->request_fingerprint, $fingerprint)) {
            throw new ConflictHttpException('Idempotency key sudah digunakan untuk payload yang berbeda.');
        }

        return $request->load('merchant');
    }

    /** @param array{code: string, name: string, email: string|null, environment: string, idempotency_key: string} $attributes */
    private function matchingProvisionedMerchant(array $attributes, string $serviceId, string $fingerprint): ?InternalMerchantProvisioningRequest
    {
        $merchant = Merchant::query()->where('code', $attributes['code'])->lockForUpdate()->first();
        if ($merchant === null) {
            return null;
        }

        $request = InternalMerchantProvisioningRequest::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('service_id', $serviceId)
            ->where('environment', $attributes['environment'])
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if ($request !== null && hash_equals($request->request_fingerprint, $fingerprint)) {
            return $request->load('merchant');
        }

        throw new ConflictHttpException('Kode merchant sudah digunakan oleh identitas atau environment berbeda.');
    }

    /**
     * @param  array{code: string, name: string, email: string|null, environment: string, idempotency_key: string}  $attributes
     */
    private function resolveConcurrentRequest(array $attributes, string $serviceId, string $fingerprint, QueryException $exception): InternalMerchantProvisioningRequest
    {
        $existing = InternalMerchantProvisioningRequest::query()
            ->where('service_id', $serviceId)
            ->where('idempotency_key', $attributes['idempotency_key'])
            ->first();

        if ($existing !== null) {
            return $this->sameRequestOrConflict($existing, $fingerprint);
        }

        if (Merchant::query()->where('code', $attributes['code'])->exists()) {
            throw new ConflictHttpException('Kode merchant sudah digunakan.');
        }

        throw $exception;
    }
}
