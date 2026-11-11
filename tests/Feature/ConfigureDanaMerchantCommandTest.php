<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\DanaSandboxKeyMaterialFixture;
use Tests\TestCase;

class ConfigureDanaMerchantCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_rejects_invalid_environment(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'MRC-TEST-001']);

        $this->artisan('merchant:dana:configure', [
            'merchant' => $merchant->code,
            '--environment' => 'invalid_env',
        ])
            ->expectsOutput('Environment harus "sandbox" atau "production".')
            ->assertExitCode(1);
    }

    public function test_command_rejects_non_pem_key_file(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'MRC-TEST-002']);
        $invalidKeyPath = tempnam(sys_get_temp_dir(), 'invalid_key');
        file_put_contents($invalidKeyPath, 'not a valid pem content');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bukan PEM yang valid');

        try {
            $this->artisan('merchant:dana:configure', [
                'merchant' => $merchant->code,
                '--environment' => 'sandbox',
                '--private-key-path' => $invalidKeyPath,
                '--public-key-path' => $invalidKeyPath,
            ]);
        } finally {
            @unlink($invalidKeyPath);
        }
    }

    public function test_command_configures_production_merchant_safely(): void
    {
        $merchant = Merchant::factory()->create(['code' => 'MRC-PROD-001']);

        $privateKeyPath = tempnam(sys_get_temp_dir(), 'test_priv');
        $publicKeyPath = tempnam(sys_get_temp_dir(), 'test_pub');
        file_put_contents($privateKeyPath, DanaSandboxKeyMaterialFixture::PrivateKey);
        file_put_contents($publicKeyPath, DanaSandboxKeyMaterialFixture::PublicKey);

        try {
            $this->artisan('merchant:dana:configure', [
                'merchant' => $merchant->code,
                '--environment' => 'production',
                '--private-key-path' => $privateKeyPath,
                '--public-key-path' => $publicKeyPath,
            ])
                ->expectsQuestion('X-PARTNER-ID', 'PROD-PARTNER-001')
                ->expectsQuestion('URL PAY_RETURN HTTPS', 'https://merchant.example/payment-return')
                ->expectsQuestion('DANA Merchant ID', 'PROD-MERCHANT-001')
                ->expectsQuestion('ORIGIN', 'merchant.example')
                ->expectsQuestion('CHANNEL-ID', '95221')
                ->expectsQuestion('URL NOTIFICATION HTTPS', 'https://merchant.example/api/v1/webhooks/dana')
                ->expectsQuestion('MCC', '5411')
                ->expectsOutput('Konfigurasi DANA production tersimpan terenkripsi.')
                ->assertExitCode(0);

            $config = MerchantPaymentConfig::query()
                ->where('merchant_id', $merchant->id)
                ->where('environment', 'production')
                ->firstOrFail();

            $this->assertSame('PROD-PARTNER-001', $config->partner_id);
            $this->assertSame('https://merchant.example/payment-return', $config->callback_url);
            $this->assertTrue($config->enabled);
            $this->assertSame('PROD-MERCHANT-001', $config->encrypted_credentials['merchant_id']);
            $this->assertSame('5411', $config->encrypted_credentials['mcc']);

            // Verify raw database row is encrypted (not plaintext)
            $rawRow = DB::table('merchant_payment_configs')
                ->where('id', $config->id)
                ->first();
            $this->assertStringNotContainsString('PROD-MERCHANT-001', (string) $rawRow->encrypted_credentials);
            $this->assertStringNotContainsString('BEGIN PRIVATE KEY', (string) $rawRow->encrypted_credentials);
        } finally {
            @unlink($privateKeyPath);
            @unlink($publicKeyPath);
        }
    }
}
