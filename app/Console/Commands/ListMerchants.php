<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('merchant:list')]
#[Description('Menampilkan daftar merchant dan metadata API key')]
class ListMerchants extends Command
{
    public function handle(): int
    {
        $merchants = Merchant::query()->withCount('apiKeys')->orderBy('code')->get();
        $this->table(['Kode', 'Nama', 'Status', 'API keys'], $merchants->map(fn (Merchant $merchant): array => [$merchant->code, $merchant->name, $merchant->status, $merchant->api_keys_count])->all());

        return self::SUCCESS;
    }
}
