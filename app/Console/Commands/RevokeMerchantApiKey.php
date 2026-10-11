<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Services\MerchantApiKeyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('merchant:api-key:revoke {merchant : Kode merchant} {key : ID API key}')]
#[Description('Mencabut API key merchant')]
class RevokeMerchantApiKey extends Command
{
    public function handle(MerchantApiKeyService $apiKeyService): int
    {
        $merchant = Merchant::query()->where('code', $this->argument('merchant'))->firstOrFail();
        $apiKey = MerchantApiKey::query()->where('merchant_id', $merchant->getKey())->findOrFail($this->argument('key'));
        $apiKeyService->revoke($apiKey);
        $this->info('API key dicabut.');

        return self::SUCCESS;
    }
}
