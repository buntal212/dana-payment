<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingWebhookOutbox extends Model
{
    use HasFactory;

    public const string StatusPending = 'pending';

    public const string StatusProcessing = 'processing';

    public const string StatusDelivered = 'delivered';

    public const string StatusFailed = 'failed';

    protected $fillable = [
        'payment_id',
        'environment',
        'event_type',
        'target_url',
        'idempotency_key',
        'payload',
        'status',
        'attempts',
        'max_attempts',
        'available_at',
        'locked_at',
        'delivered_at',
        'last_error',
        'last_http_status',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'delivered_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'last_http_status' => 'integer',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isDelivered(): bool
    {
        return $this->status === self::StatusDelivered;
    }

    public function isFailed(): bool
    {
        return $this->status === self::StatusFailed;
    }

    public function canRetry(): bool
    {
        return $this->status === self::StatusPending && $this->attempts < $this->max_attempts;
    }
}
