<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Internal\RevokeInternalMerchantApiKeyRequest;
use App\Http\Requests\Api\V1\Internal\StoreInternalMerchantDanaConfigurationRequest;
use App\Http\Resources\InternalMerchantApiKeyResource;
use App\Http\Resources\InternalMerchantReadinessResource;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Services\InternalMerchantManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MerchantManagementController extends Controller
{
    public function readiness(Merchant $merchant, InternalMerchantManagementService $merchantManagementService): InternalMerchantReadinessResource
    {
        return new InternalMerchantReadinessResource($merchantManagementService->readiness($merchant, $this->serviceId()));
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

    private function serviceId(): string
    {
        return (string) request()->attributes->get('internal_service_id');
    }
}
