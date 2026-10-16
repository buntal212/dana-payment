<?php

namespace App\Models;

use Database\Factories\InternalMerchantApiKeyIssuanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalMerchantApiKeyIssuance extends Model
{
    public const string StatusPending = 'pending';

    public const string StatusConfirmed = 'confirmed';

    public const string StatusCancelled = 'cancelled';

    public const string StatusExpired = 'expired';

    /** @use HasFactory<InternalMerchantApiKeyIssuanceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['merchant_id', 'service_id', 'idempotency_key', 'request_fingerprint', 'name', 'key_prefix', 'key_hash', 'abilities', 'expires_at', 'status', 'pending_expires_at', 'confirmed_at', 'cancelled_at', 'merchant_api_key_id'];

    /** @var list<string> */
    protected $hidden = ['key_hash', 'request_fingerprint', 'idempotency_key'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'expires_at' => 'datetime', 'pending_expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function merchantApiKey(): BelongsTo
    {
        return $this->belongsTo(MerchantApiKey::class);
    }
}
