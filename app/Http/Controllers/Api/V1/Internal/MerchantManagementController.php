<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Internal\CancelInternalMerchantApiKeyIssuanceRequest;
use App\Http\Requests\Api\V1\Internal\ConfirmInternalMerchantApiKeyIssuanceRequest;
use App\Http\Requests\Api\V1\Internal\IssueInternalMerchantApiKeyRequest;
use App\Http\Requests\Api\V1\Internal\RevokeInternalMerchantApiKeyRequest;
use App\Http\Requests\Api\V1\Internal\StoreInternalMerchantDanaConfigurationRequest;
use App\Http\Resources\InternalMerchantApiKeyResource;
use App\Http\Resources\InternalMerchantReadinessResource;
use App\Models\InternalMerchantApiKeyIssuance;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Services\InternalMerchantApiKeyIssuanceService;
use App\Services\InternalMerchantManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MerchantManagementController extends Controller
{
    public function readiness(Merchant $merchant, InternalMerchantManagementService $merchantManagementService): InternalMerchantReadinessResource
    {
        return new InternalMerchantReadinessResource($merchantManagementService->readiness($merchant, $this->serviceId()));
    }

    public function danaConfiguration(Merchant $merchant, InternalMerchantManagementService $merchantManagementService): JsonResponse
    {
        return response()->json(['data' => $merchantManagementService->danaConfiguration($merchant, $this->serviceId())])
            ->header('Cache-Control', 'no-store');
    }

    public function configureDanaSandbox(Merchant $merchant, StoreInternalMerchantDanaConfigurationRequest $request, InternalMerchantManagementService $merchantManagementService): JsonResponse
    {
        $result = $merchantManagementService->configureDanaSandbox($merchant, $request->validated(), $this->serviceId());

        return response()->json(['data' => $result['result']]);
    }

    public function apiKeys(Merchant $merchant, InternalMerchantManagementService $merchantManagementService): AnonymousResourceCollection
    {
        return InternalMerchantApiKeyResource::collection($merchantManagementService->apiKeys($merchant, $this->serviceId()));
    }

    public function revokeApiKey(Merchant $merchant, MerchantApiKey $apiKey, RevokeInternalMerchantApiKeyRequest $request, InternalMerchantManagementService $merchantManagementService): JsonResponse
    {
        $result = $merchantManagementService->revokeApiKey($merchant, $apiKey, $request->validated(), $this->serviceId());

        return response()->json(['data' => $result['result']]);
    }

    public function issueApiKey(Merchant $merchant, IssueInternalMerchantApiKeyRequest $request, InternalMerchantApiKeyIssuanceService $issuanceService): JsonResponse
    {
        $result = $issuanceService->issue($merchant, [...$request->validated(), 'idempotency_key' => $request->idempotencyKey()], $this->serviceId());
        $data = ['issuance' => $this->issuance($result['issuance'])];
        if ($result['api_key'] !== null) {
            $data['api_key'] = $result['api_key'];
        }

        return response()->json(['data' => $data], $result['replayed'] ? 200 : 201)->header('Cache-Control', 'no-store');
    }

    public function issuanceStatus(Merchant $merchant, InternalMerchantApiKeyIssuance $issuance, InternalMerchantApiKeyIssuanceService $issuanceService): JsonResponse
    {
        return response()->json(['data' => $this->issuance($issuanceService->status($merchant, $issuance, $this->serviceId()))])->header('Cache-Control', 'no-store');
    }

    public function confirmApiKeyIssuance(Merchant $merchant, InternalMerchantApiKeyIssuance $issuance, ConfirmInternalMerchantApiKeyIssuanceRequest $request, InternalMerchantApiKeyIssuanceService $issuanceService): JsonResponse
    {
        return response()->json(['data' => $issuanceService->confirm($merchant, $issuance, [...$request->validated(), 'idempotency_key' => $request->idempotencyKey()], $this->serviceId())])->header('Cache-Control', 'no-store');
    }

    public function cancelApiKeyIssuance(Merchant $merchant, InternalMerchantApiKeyIssuance $issuance, CancelInternalMerchantApiKeyIssuanceRequest $request, InternalMerchantApiKeyIssuanceService $issuanceService): JsonResponse
    {
        return response()->json(['data' => $issuanceService->cancel($merchant, $issuance, [...$request->validated(), 'idempotency_key' => $request->idempotencyKey()], $this->serviceId())])->header('Cache-Control', 'no-store');
    }

    private function issuance(InternalMerchantApiKeyIssuance $issuance): array
    {
        return ['id' => $issuance->getKey(), 'status' => $issuance->status, 'key_prefix' => $issuance->key_prefix, 'pending_expires_at' => $issuance->pending_expires_at?->toIso8601String(), 'merchant_api_key_id' => $issuance->merchant_api_key_id];
    }

    private function serviceId(): string
    {
        return (string) request()->attributes->get('internal_service_id');
    }
}
