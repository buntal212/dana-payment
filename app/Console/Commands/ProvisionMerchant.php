<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Services\MerchantApiKeyService;
use App\Services\MerchantProvisioningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('merchant:provision {code : Kode merchant unik} {name : Nama merchant} {--email= : Email kontak} {--key-name=default : Nama API key awal}')]
#[Description('Membuat merchant dan menampilkan API key awal sekali saja')]
class ProvisionMerchant extends Command
{
    public function handle(MerchantApiKeyService $apiKeyService, MerchantProvisioningService $merchantProvisioningService): int
    {
        $result = DB::transaction(function () use ($apiKeyService, $merchantProvisioningService): array {
            $merchant = $merchantProvisioningService->createMerchant([
                'code' => $this->argument('code'),
                'name' => $this->argument('name'),
                'email' => $this->option('email'),
            ]);

            return ['merchant' => $merchant, ...$apiKeyService->create($merchant, $this->option('key-name'))];
        });

        /** @var Merchant $merchant */
        $merchant = $result['merchant'];
        $this->info("Merchant {$merchant->code} dibuat.");
        $this->warn('Simpan API key berikut sekarang; nilai ini tidak dapat ditampilkan kembali.');
        $this->line($result['api_key']);

        return self::SUCCESS;
    }
}
