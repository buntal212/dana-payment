<?php

namespace App\Models;

use Database\Factories\MerchantApiKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantApiKey extends Model
{
    /** @use HasFactory<MerchantApiKeyFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['merchant_id', 'name', 'key_prefix', 'key_hash', 'abilities', 'expires_at', 'last_used_at', 'revoked_at'];

    /** @var list<string> */
    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @param Builder<self> $query */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
