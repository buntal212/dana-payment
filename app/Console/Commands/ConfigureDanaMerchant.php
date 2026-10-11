<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('merchant:dana:configure {merchant : Kode merchant} {--private-key-path= : Path PEM private key merchant} {--public-key-path= : Path PEM public key webhook DANA yang diverifikasi}')]
#[Description('Menyimpan konfigurasi DANA Sandbox merchant secara terenkripsi')]
class ConfigureDanaMerchant extends Command
{
    public function handle(): int
    {
        $merchant = Merchant::query()->where('code', $this->argument('merchant'))->firstOrFail();
        $privateKeyPath = $this->option('private-key-path') ?? $this->ask('Path PEM private key merchant');
        $publicKeyPath = $this->option('public-key-path') ?? $this->ask('Path PEM public key webhook DANA Sandbox yang diverifikasi dari dokumentasi atau portal resmi');
        $privateKey = $this->readPem((string) $privateKeyPath, 'private key merchant');
        $publicKey = $this->readPem((string) $publicKeyPath, 'public key DANA');

        DB::transaction(function () use ($merchant, $privateKey, $publicKey): void {
            MerchantPaymentConfig::query()->updateOrCreate(
                ['merchant_id' => $merchant->getKey(), 'provider' => 'dana', 'environment' => 'sandbox'],
                [
                    'partner_id' => (string) $this->ask('X-PARTNER-ID'),
                    'callback_url' => (string) $this->ask('URL PAY_RETURN HTTPS'),
                    'enabled' => true,
                    'encrypted_credentials' => [
                        'merchant_id' => (string) $this->ask('DANA Merchant ID'),
                        'private_key' => $privateKey,
                        'dana_webhook_public_key' => $publicKey,
                        'origin' => (string) $this->ask('ORIGIN'),
                        'channel_id' => (string) $this->ask('CHANNEL-ID'),
                        'notification_url' => (string) $this->ask('URL NOTIFICATION HTTPS'),
                        'mcc' => (string) $this->ask('MCC'),
                    ],
                ],
            );
        });

        $this->info('Konfigurasi DANA Sandbox tersimpan terenkripsi.');

        return self::SUCCESS;
    }

    private function readPem(string $path, string $label): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("Berkas {$label} tidak dapat dibaca.");
        }

        $pem = file_get_contents($path);
        if ($pem === false || ! str_contains($pem, '-----BEGIN')) {
            throw new \InvalidArgumentException("Berkas {$label} bukan PEM yang valid.");
        }

        return $pem;
    }
}
