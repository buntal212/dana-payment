<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantApiKey;
use Illuminate\Support\Facades\Log;

class MerchantApiKeyService
{
    /**
     * @return array{api_key: string, api_key_record: MerchantApiKey}
     */
    public function create(Merchant $merchant, string $name, ?\DateTimeInterface $expiresAt = null, ?array $abilities = null): array
    {
        $apiKey = $this->generateApiKey();

        $record = $merchant->apiKeys()->create([
            'name' => $name,
            'key_prefix' => $this->prefix($apiKey),
            'key_hash' => hash('sha256', $apiKey),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        return ['api_key' => $apiKey, 'api_key_record' => $record];
    }

    public function generateApiKey(): string
    {
        return 'dpk_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function authenticate(string $apiKey, ?string $ability = null): ?MerchantApiKey
    {
        $record = MerchantApiKey::query()
            ->with('merchant')
            ->usable()
            ->where('key_prefix', $this->prefix($apiKey))
            ->first();

        if ($record === null || ! hash_equals($record->key_hash, hash('sha256', $apiKey))) {
            return null;
        }

        if ($record->merchant === null || $record->merchant->status !== 'active' || ! $this->can($record, $ability)) {
            return null;
        }

        $record->forceFill(['last_used_at' => now()])->save();

        return $record;
    }

    public function revoke(MerchantApiKey $apiKey): void
    {
        $apiKey->forceFill(['revoked_at' => now()])->save();

        Log::info('Merchant API key revoked.', ['merchant_id' => $apiKey->merchant_id, 'api_key_id' => $apiKey->getKey()]);
    }

    private function can(MerchantApiKey $apiKey, ?string $ability): bool
    {
        if ($ability === null || $apiKey->abilities === null) {
            return true;
        }

        return in_array('*', $apiKey->abilities, true)
            || in_array($ability, $apiKey->abilities, true)
            || in_array(strtok($ability, ':').':*', $apiKey->abilities, true);
    }

    private function prefix(string $apiKey): string
    {
        return substr($apiKey, 0, 24);
    }
}
