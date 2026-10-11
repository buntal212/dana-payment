<?php

namespace App\Models;

use Database\Factories\PaymentRefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    /** @use HasFactory<PaymentRefundFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['public_id', 'merchant_id', 'payment_id', 'merchant_refund_id', 'provider_refund_id', 'amount_minor', 'currency', 'status', 'reason', 'completed_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'completed_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
