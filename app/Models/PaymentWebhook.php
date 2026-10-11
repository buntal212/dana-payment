<?php

namespace App\Models;

use Database\Factories\PaymentWebhookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhook extends Model
{
    /** @use HasFactory<PaymentWebhookFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['merchant_id', 'payment_id', 'provider', 'environment', 'payload_sha256', 'provider_event_id', 'signature_valid', 'processing_status', 'attempts', 'processed_at', 'error_code'];

    protected function casts(): array
    {
        return ['signature_valid' => 'boolean', 'processed_at' => 'datetime'];
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
