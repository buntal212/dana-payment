<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Internal\ListInternalMerchantRequest;
use App\Http\Requests\Api\V1\Internal\StoreInternalMerchantRequest;
use App\Http\Requests\Api\V1\Internal\UpdateInternalMerchantStatusRequest;
use App\Http\Resources\InternalMerchantResource;
use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\MerchantProvisioningAudit;
use App\Services\MerchantProvisioningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class MerchantProvisioningController extends Controller
{
    public function index(ListInternalMerchantRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $requests = InternalMerchantProvisioningRequest::query()
            ->with('merchant')
            ->where('service_id', $this->serviceId($request))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->whereHas('merchant', function (Builder $merchantQuery) use ($search): void {
                    $merchantQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($validated['status'] ?? null, function (Builder $query, string $status): void {
                $query->whereHas('merchant', fn (Builder $merchantQuery): Builder => $merchantQuery->where('status', $status));
            })
            ->when($validated['environment'] ?? null, fn (Builder $query, string $environment): Builder => $query->where('environment', $environment))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return InternalMerchantResource::collection($requests);
    }

    public function store(StoreInternalMerchantRequest $request, MerchantProvisioningService $merchantProvisioningService): JsonResponse
    {
        $provisioningRequest = $merchantProvisioningService->provisionForInternalService(
            $request->validated(),
            $this->serviceId($request),
        );
        $isNew = $provisioningRequest->wasRecentlyCreated;

        return (new InternalMerchantResource($provisioningRequest))
            ->response()
            ->setStatusCode($isNew ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function show(Merchant $merchant, ListInternalMerchantRequest $request): InternalMerchantResource
    {
        $provisioningRequest = $merchant->internalProvisioningRequests()
            ->with('merchant')
            ->where('service_id', $this->serviceId($request))
            ->latest('id')
            ->firstOrFail();

        return new InternalMerchantResource($provisioningRequest);
    }

    public function updateStatus(Merchant $merchant, UpdateInternalMerchantStatusRequest $request): JsonResponse
    {
        if ($merchant->code === 'udev-central') {
            return response()->json([
                'status' => 'error',
                'message' => 'Merchant udev-central adalah merchant sistem pusat dan tidak dapat diubah statusnya.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validated();
        $newStatus = $validated['status'];

        if ($newStatus !== 'active') {
            $pendingCount = $merchant->payments()
                ->whereIn('status', ['pending', 'initiated'])
                ->count();

            if ($pendingCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Merchant memiliki {$pendingCount} transaksi pending dan tidak dapat dinonaktifkan.",
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $merchant->update(['status' => $newStatus]);

        $fingerprint = hash('sha256', "status-update:{$merchant->id}:{$newStatus}");
        MerchantProvisioningAudit::query()->create([
            'merchant_id' => $merchant->getKey(),
            'service_id' => $this->serviceId($request),
            'operation' => $newStatus === 'active' ? 'merchant.activated' : 'merchant.deactivated',
            'request_fingerprint' => $fingerprint,
            'safe_details' => [
                'merchant_code' => $merchant->code,
                'status' => $newStatus,
            ],
        ]);

        $provisioningRequest = $merchant->internalProvisioningRequests()
            ->with('merchant')
            ->where('service_id', $this->serviceId($request))
            ->latest('id')
            ->first();

        if ($provisioningRequest !== null) {
            return (new InternalMerchantResource($provisioningRequest))
                ->response()
                ->setStatusCode(Response::HTTP_OK);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Status merchant berhasil diperbarui.',
            'data' => [
                'merchant_id' => $merchant->id,
                'merchant_code' => $merchant->code,
                'merchant_name' => $merchant->name,
                'merchant_status' => $merchant->status,
                'status' => $merchant->status,
            ],
        ], Response::HTTP_OK);
    }

    public function destroy(Merchant $merchant, Request $request): JsonResponse
    {
        if ($merchant->code === 'udev-central') {
            return response()->json([
                'status' => 'error',
                'message' => 'Merchant udev-central adalah merchant sistem pusat dan tidak dapat dihapus.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pendingCount = $merchant->payments()
            ->whereIn('status', ['pending', 'initiated'])
            ->count();

        if ($pendingCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Merchant memiliki {$pendingCount} transaksi pending dan tidak dapat dihapus.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $historyCount = $merchant->payments()->count();

        if ($historyCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Merchant memiliki riwayat {$historyCount} transaksi pembayaran dan tidak dapat dihapus. Silakan nonaktifkan merchant sebagai gantinya.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $fingerprint = hash('sha256', "merchant-delete:{$merchant->id}");
        MerchantProvisioningAudit::query()->create([
            'merchant_id' => $merchant->getKey(),
            'service_id' => $this->serviceId($request),
            'operation' => 'merchant.deleted',
            'request_fingerprint' => $fingerprint,
            'safe_details' => [
                'merchant_code' => $merchant->code,
            ],
        ]);

        $merchant->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Merchant berhasil dihapus.',
        ], Response::HTTP_OK);
    }

    private function serviceId(Request $request): string
    {
        return (string) $request->attributes->get('internal_service_id', 'udev-billing');
    }
}
