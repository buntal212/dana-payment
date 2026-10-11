<?php

namespace App\Models;

use Database\Factories\MerchantPaymentConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantPaymentConfig extends Model
{
    /** @use HasFactory<MerchantPaymentConfigFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['merchant_id', 'provider', 'environment', 'partner_id', 'encrypted_credentials', 'callback_url', 'enabled'];

    /** @var list<string> */
    protected $hidden = ['encrypted_credentials'];

    protected function casts(): array
    {
        return ['encrypted_credentials' => 'encrypted:array', 'enabled' => 'boolean'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
