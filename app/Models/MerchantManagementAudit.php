<?php

namespace App\Models;

use Database\Factories\MerchantManagementAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantManagementAudit extends Model
{
    /** @use HasFactory<MerchantManagementAuditFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['merchant_id', 'service_id', 'operation', 'request_fingerprint', 'safe_details'];

    protected function casts(): array
    {
        return ['safe_details' => 'array'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
