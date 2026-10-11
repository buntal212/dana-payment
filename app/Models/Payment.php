<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['public_id', 'merchant_id', 'merchant_order_id', 'provider', 'environment', 'provider_order_id', 'provider_transaction_id', 'amount_minor', 'currency', 'status', 'checkout_url', 'idempotency_key', 'expires_at', 'paid_at', 'failed_at', 'metadata'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'expires_at' => 'datetime', 'paid_at' => 'datetime', 'failed_at' => 'datetime', 'metadata' => 'array'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /** @param Builder<self> $query */
    public function scopeForMerchant(Builder $query, Merchant|int $merchant): Builder
    {
        return $query->where('merchant_id', $merchant instanceof Merchant ? $merchant->getKey() : $merchant);
    }
}
