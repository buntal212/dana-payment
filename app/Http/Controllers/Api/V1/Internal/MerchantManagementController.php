<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Internal\CancelInternalMerchantApiKeyIssuanceRequest;
use App\Http\Requests\Api\V1\Internal\ConfirmInternalMerchantApiKeyIssuanceRequest;
use App\Http\Requests\Api\V1\Internal\IssueInternalMerchantApiKeyRequest;
use App\Http\Requests\Api\V1\Internal\RevokeInternalMerchantApiKeyRequest;
use App\Http\Requests\Api\V1\Internal\StoreDanaSandboxTestTransactionRequest;
use App\Http\Requests\Api\V1\Internal\StoreInternalMerchantDanaConfigurationRequest;
use App\Http\Resources\InternalMerchantApiKeyResource;
use App\Http\Resources\InternalMerchantReadinessResource;
use App\Models\InternalMerchantApiKeyIssuance;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Services\DanaSandboxTesterService;
use App\Services\InternalMerchantApiKeyIssuanceService;
use App\Services\InternalMerchantManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json(['data' => $result['result']])
            ->header('Cache-Control', 'no-store');
    }

    public function validateDanaSandboxTester(Merchant $merchant, DanaSandboxTesterService $testerService): JsonResponse
    {
        return response()->json(['data' => $testerService->validateConfiguration($merchant, $this->serviceId())])
            ->header('Cache-Control', 'no-store');
    }

    public function testCreateOrderMissingTimestamp(Merchant $merchant, DanaSandboxTesterService $testerService): JsonResponse
    {
        return response()->json(['data' => $testerService->testCreateOrderMissingTimestamp($merchant, $this->serviceId())])
            ->header('Cache-Control', 'no-store');
    }

    public function testCreateOrderInvalidAmountFormat(Merchant $merchant, DanaSandboxTesterService $testerService): JsonResponse
    {
        return response()->json(['data' => $testerService->testCreateOrderInvalidAmountFormat($merchant, $this->serviceId())])
            ->header('Cache-Control', 'no-store');
    }

    public function testCreateOrderInconsistentRequest(Merchant $merchant, DanaSandboxTesterService $testerService): JsonResponse
    {
        return response()->json(['data' => $testerService->testCreateOrderInconsistentRequest($merchant, $this->serviceId())])
            ->header('Cache-Control', 'no-store');
    }

    public function testDebitStatusTransactionNotFound(Merchant $merchant, DanaSandboxTesterService $testerService): JsonResponse
    {
        return response()->json(['data' => $testerService->testDebitStatusTransactionNotFound($merchant, $this->serviceId())])
            ->header('Cache-Control', 'no-store');
    }

    public function armDanaSandboxWebhookSimulated500(Merchant $merchant, Request $request, DanaSandboxTesterService $testerService): JsonResponse
    {
        $paymentId = $request->input('payment_id');

        return response()->json(['data' => $testerService->armWebhookSimulated500($merchant, is_string($paymentId) ? $paymentId : null, $this->serviceId())])
            ->header('Cache-Control', 'no-store');
    }

    public function createDanaSandboxTestTransaction(Merchant $merchant, StoreDanaSandboxTestTransactionRequest $request, DanaSandboxTesterService $testerService): JsonResponse
    {
        return response()->json(['data' => $testerService->createTransaction($merchant, $request->transactionAttributes(), $this->serviceId())], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function danaSandboxTestTransactionStatus(Merchant $merchant, string $paymentId, DanaSandboxTesterService $testerService): JsonResponse
    {
        return response()->json(['data' => $testerService->transactionStatus($merchant, $paymentId, $this->serviceId())])
            ->header('Cache-Control', 'no-store');
    }

    public function queryDanaSandboxPaymentStatus(Merchant $merchant, string $paymentId, DanaSandboxTesterService $testerService): JsonResponse
    {
        return response()->json(['data' => $testerService->queryDanaPaymentStatus($merchant, $paymentId, $this->serviceId())])
            ->header('Cache-Control', 'no-store');
    }

    public function danaSandboxTestHistory(Merchant $merchant, Request $request, DanaSandboxTesterService $testerService): JsonResponse
    {
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $runs = $testerService->history($merchant, $this->serviceId(), $validated['per_page'] ?? 15);

        return response()->json([
            'data' => $runs->getCollection()->map(fn ($run): array => [
                'id' => $run->getKey(),
                'merchant_code' => $merchant->code,
                'environment' => $run->environment,
                'operation' => $run->operation,
                'status' => $run->status,
                'message' => $run->message,
                'payment_id' => $run->payment?->public_id,
                'payment_status' => $run->payment?->status,
                'created_at' => $run->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
                'last_page' => $runs->lastPage(),
            ],
        ])->header('Cache-Control', 'no-store');
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
