<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\MerchantResource;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MerchantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'string', 'in:active,suspended,closed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $merchants = Merchant::query()
            ->withCount('payments')
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $merchantQuery) use ($search): void {
                    $merchantQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($validated['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();

        return MerchantResource::collection($merchants);
    }

    public function show(Merchant $merchant): MerchantResource
    {
        $merchant->loadCount('payments');

        return new MerchantResource($merchant);
    }
}
