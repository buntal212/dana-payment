<?php

namespace App\Models;

use Database\Factories\InternalMerchantManagementRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalMerchantManagementRequest extends Model
{
    public const string StatusCompleted = 'completed';

    /** @use HasFactory<InternalMerchantManagementRequestFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['service_id', 'operation', 'idempotency_key', 'request_fingerprint', 'merchant_id', 'status', 'safe_result'];

    protected function casts(): array
    {
        return ['safe_result' => 'array'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
