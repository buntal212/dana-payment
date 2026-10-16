<?php

namespace App\Models;

use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'contact_email', 'status'];

    public function apiKeys(): HasMany
    {
        return $this->hasMany(MerchantApiKey::class);
    }

    public function paymentConfigs(): HasMany
    {
        return $this->hasMany(MerchantPaymentConfig::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function internalProvisioningRequests(): HasMany
    {
        return $this->hasMany(InternalMerchantProvisioningRequest::class);
    }

    public function provisioningAudits(): HasMany
    {
        return $this->hasMany(MerchantProvisioningAudit::class);
    }

    public function internalManagementRequests(): HasMany
    {
        return $this->hasMany(InternalMerchantManagementRequest::class);
    }

    public function managementAudits(): HasMany
    {
        return $this->hasMany(MerchantManagementAudit::class);
    }

    public function internalApiKeyIssuances(): HasMany
    {
        return $this->hasMany(InternalMerchantApiKeyIssuance::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
