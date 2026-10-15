<?php

namespace App\Http\Resources;

use App\Models\InternalMerchantProvisioningRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InternalMerchantProvisioningRequest */
class InternalMerchantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'merchant_id' => $this->merchant_id,
            'merchant_code' => $this->merchant->code,
            'merchant_name' => $this->merchant->name,
            'environment' => $this->environment,
            'merchant_status' => $this->merchant->status,
            'provisioning_status' => $this->status,
            'credential_status' => 'bootstrap_required',
            'dana_configuration_status' => 'required',
            'provisioned_at' => $this->created_at,
        ];
    }
}
