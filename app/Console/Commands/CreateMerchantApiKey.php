<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Services\MerchantApiKeyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('merchant:api-key:create {merchant : Kode merchant} {name : Nama API key} {--revoke-id= : ID key lama yang dicabut setelah key baru dibuat}')]
#[Description('Membuat atau merotasi API key merchant; nilai plaintext hanya ditampilkan sekali')]
class CreateMerchantApiKey extends Command
{
    public function handle(MerchantApiKeyService $apiKeyService): int
    {
        $merchant = Merchant::query()->where('code', $this->argument('merchant'))->firstOrFail();
        $result = DB::transaction(function () use ($apiKeyService, $merchant): array {
            $result = $apiKeyService->create($merchant, $this->argument('name'));

            if ($this->option('revoke-id') !== null) {
                $oldKey = MerchantApiKey::query()->where('merchant_id', $merchant->getKey())->findOrFail($this->option('revoke-id'));
                $apiKeyService->revoke($oldKey);
            }

            return $result;
        });

        $this->warn('Simpan API key berikut sekarang; nilai ini tidak dapat ditampilkan kembali.');
        $this->line($result['api_key']);

        return self::SUCCESS;
    }
}
