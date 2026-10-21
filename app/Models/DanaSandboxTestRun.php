<?php

namespace App\Models;

use Database\Factories\DanaSandboxTestRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DanaSandboxTestRun extends Model
{
    /** @use HasFactory<DanaSandboxTestRunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['merchant_id', 'payment_id', 'operation', 'environment', 'status', 'message', 'safe_details'];

    protected function casts(): array
    {
        return ['safe_details' => 'array'];
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
