<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $instruction = $metadata['payment_instruction']
            ?? $metadata['udev_sandbox_payment_instruction']
            ?? null;

        return [
            'id' => $this->public_id,
            'partner_reference_no' => $this->partner_reference_no,
            'merchant_order_id' => $this->merchant_order_id,
            'provider_order_id' => $this->provider_order_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status,
            'checkout_url' => $this->checkout_url,
            'expires_at' => $this->expires_at,
            'paid_at' => $this->paid_at,
            'failed_at' => $this->failed_at,
            'payment_instruction' => $instruction,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
