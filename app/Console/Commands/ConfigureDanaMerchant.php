<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Services\DanaKeyMaterialException;
use App\Services\DanaKeyMaterialNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('merchant:dana:configure {merchant : Kode merchant} {--environment=sandbox : Environment (sandbox atau production)} {--private-key-path= : Path PEM private key merchant} {--public-key-path= : Path PEM public key webhook DANA yang diverifikasi}')]
#[Description('Menyimpan konfigurasi DANA merchant secara terenkripsi')]
class ConfigureDanaMerchant extends Command
{
    public function handle(DanaKeyMaterialNormalizer $normalizer): int
    {
        $merchant = Merchant::query()->where('code', $this->argument('merchant'))->firstOrFail();
        $environment = strtolower(trim((string) ($this->option('environment') ?: 'sandbox')));

        if (! in_array($environment, ['sandbox', 'production'], true)) {
            $this->error('Environment harus "sandbox" atau "production".');

            return self::FAILURE;
        }

        $privateKeyPath = $this->option('private-key-path') ?? $this->ask('Path PEM private key merchant');
        $publicKeyPath = $this->option('public-key-path') ?? $this->ask("Path PEM public key webhook DANA {$environment} yang diverifikasi");
        $rawPrivateKey = $this->readPem((string) $privateKeyPath, 'private key merchant');
        $rawPublicKey = $this->readPem((string) $publicKeyPath, 'public key DANA');

        try {
            $privateKey = $normalizer->normalizePrivateKey($rawPrivateKey);
            $publicKey = $normalizer->normalizePublicKey($rawPublicKey);
        } catch (DanaKeyMaterialException $e) {
            $this->error('Validasi RSA key gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        DB::transaction(function () use ($merchant, $environment, $privateKey, $publicKey): void {
            MerchantPaymentConfig::query()->updateOrCreate(
                ['merchant_id' => $merchant->getKey(), 'provider' => 'dana', 'environment' => $environment],
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

        $this->info("Konfigurasi DANA {$environment} tersimpan terenkripsi.");

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
