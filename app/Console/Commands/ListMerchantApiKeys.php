<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('merchant:api-key:list {merchant : Kode merchant}')]
#[Description('Menampilkan metadata API key merchant tanpa hash atau plaintext')]
class ListMerchantApiKeys extends Command
{
    public function handle(): int
    {
        $merchant = Merchant::query()->where('code', $this->argument('merchant'))->firstOrFail();
        $apiKeys = $merchant->apiKeys()->orderByDesc('id')->get();

        $this->table(['ID', 'Nama', 'Prefix', 'Kemampuan', 'Kedaluwarsa', 'Dicabut', 'Terakhir dipakai'], $apiKeys->map(fn ($apiKey): array => [
            $apiKey->id,
            $apiKey->name,
            $apiKey->key_prefix,
            implode(', ', $apiKey->abilities ?? []),
            $apiKey->expires_at?->toIso8601String(),
            $apiKey->revoked_at?->toIso8601String(),
            $apiKey->last_used_at?->toIso8601String(),
        ])->all());

        return self::SUCCESS;
    }
}
