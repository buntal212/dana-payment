<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Internal\ListInternalMerchantRequest;
use App\Http\Requests\Api\V1\Internal\StoreInternalMerchantRequest;
use App\Http\Resources\InternalMerchantResource;
use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Services\MerchantProvisioningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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

    private function serviceId(ListInternalMerchantRequest|StoreInternalMerchantRequest $request): string
    {
        return (string) $request->attributes->get('internal_service_id');
    }
}
