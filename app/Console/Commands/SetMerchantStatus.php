<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('merchant:status {merchant : Kode merchant} {status : active, suspended, atau closed}')]
#[Description('Mengaktifkan atau menonaktifkan merchant')]
class SetMerchantStatus extends Command
{
    public function handle(): int
    {
        $status = $this->argument('status');
        if (! in_array($status, ['active', 'suspended', 'closed'], true)) {
            $this->error('Status harus salah satu dari: active, suspended, closed.');

            return self::INVALID;
        }

        $merchant = Merchant::query()->where('code', $this->argument('merchant'))->firstOrFail();
        $merchant->update(['status' => $status]);
        $this->info("Status merchant diubah menjadi {$status}.");

        return self::SUCCESS;
    }
}
