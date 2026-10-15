<?php

namespace App\Models;

use Database\Factories\InternalMerchantProvisioningRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalMerchantProvisioningRequest extends Model
{
    public const string StatusProvisioned = 'provisioned';

    /** @use HasFactory<InternalMerchantProvisioningRequestFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['service_id', 'idempotency_key', 'request_fingerprint', 'merchant_id', 'environment', 'status'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
