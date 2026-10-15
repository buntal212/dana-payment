<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Services\DanaSnapSignatureService;
use App\Services\MerchantApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MerchantPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(DanaSnapSignatureService::class, new class extends DanaSnapSignatureService
        {
            public function signTransactional(string $method, string $relativePath, string $minifiedJsonBody, string $timestamp, string $privateKey): string
            {
                return 'test-signature';
            }
        });
        Http::fake([
            'https://api.sandbox.dana.id/*' => Http::response([
                'responseCode' => '2005400',
                'referenceNo' => 'DANA-TEST-ORDER',
                'webRedirectUrl' => 'https://checkout.sandbox.dana.id/test',
            ]),
        ]);
    }

    public function test_merchant_with_a_valid_api_key_can_create_a_pending_payment(): void
    {
        [$merchant, $apiKey] = $this->merchantWithApiKey();

        $response = $this->withToken($apiKey)->postJson('/api/v1/payments', $this->paymentPayload(), ['Idempotency-Key' => 'create-payment-1']);

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.merchant_order_id', 'order-001');

        $this->assertDatabaseHas('payments', ['merchant_id' => $merchant->id, 'merchant_order_id' => 'order-001', 'status' => 'pending']);
        $this->assertDatabaseHas('payment_events', ['merchant_id' => $merchant->id, 'event_type' => 'payment.created']);
    }

    public function test_invalid_revoked_expired_and_missing_api_keys_are_rejected(): void
    {
        $this->getJson('/api/v1/payments')->assertUnauthorized();
        $this->withToken('dpk_invalid')->getJson('/api/v1/payments')->assertUnauthorized();

        [$merchant, $revokedKey] = $this->merchantWithApiKey();
        app(MerchantApiKeyService::class)->revoke($merchant->apiKeys()->firstOrFail());
        $this->withToken($revokedKey)->getJson('/api/v1/payments')->assertUnauthorized();

        [, $expiredKey] = $this->merchantWithApiKey(['expires_at' => now()->subSecond()]);
        $this->withToken($expiredKey)->getJson('/api/v1/payments')->assertUnauthorized();
    }

    public function test_inactive_merchant_is_rejected(): void
    {
        [, $apiKey] = $this->merchantWithApiKey(['status' => 'suspended']);

        $this->withToken($apiKey)->getJson('/api/v1/payments')->assertUnauthorized();
    }

    public function test_a_merchant_can_only_list_and_read_its_own_payments(): void
    {
        [$merchantA, $keyA] = $this->merchantWithApiKey();
        [$merchantB] = $this->merchantWithApiKey();
        $ownPayment = Payment::factory()->for($merchantA)->create();
        $otherPayment = Payment::factory()->for($merchantB)->create();

        $this->withToken($keyA)->getJson('/api/v1/payments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownPayment->public_id);
        $this->withToken($keyA)->getJson('/api/v1/payments/'.$otherPayment->public_id)->assertNotFound();
        $this->withToken($keyA)->getJson('/api/v1/payments/'.$ownPayment->public_id.'/status')->assertOk();
    }

    public function test_duplicate_order_id_and_different_idempotency_payload_are_rejected(): void
    {
        [, $apiKey] = $this->merchantWithApiKey();
        $payload = $this->paymentPayload();

        $this->withToken($apiKey)->postJson('/api/v1/payments', $payload, ['Idempotency-Key' => 'idempotency-a'])->assertSuccessful();
        $this->withToken($apiKey)->postJson('/api/v1/payments', $payload, ['Idempotency-Key' => 'idempotency-a'])->assertSuccessful();
        $this->assertDatabaseCount('payments', 1);

        $this->withToken($apiKey)->postJson('/api/v1/payments', [...$payload, 'amount_minor' => 20_000], ['Idempotency-Key' => 'idempotency-a'])->assertStatus(409);
        $this->withToken($apiKey)->postJson('/api/v1/payments', $payload, ['Idempotency-Key' => 'idempotency-b'])->assertStatus(409);
    }

    public function test_amount_currency_and_merchant_controlled_status_are_validated(): void
    {
        [, $apiKey] = $this->merchantWithApiKey();

        $this->withToken($apiKey)->postJson('/api/v1/payments', [...$this->paymentPayload(), 'amount_minor' => 0], ['Idempotency-Key' => 'invalid-amount'])->assertUnprocessable();
        $this->withToken($apiKey)->postJson('/api/v1/payments', [...$this->paymentPayload(), 'currency' => 'USD'], ['Idempotency-Key' => 'invalid-currency'])->assertUnprocessable();
        $this->withToken($apiKey)->postJson('/api/v1/payments', [...$this->paymentPayload(), 'status' => 'paid'], ['Idempotency-Key' => 'invalid-status'])->assertUnprocessable();
    }

    public function test_api_key_hash_is_not_exposed_by_payment_api_or_admin_routes(): void
    {
        [, $apiKey] = $this->merchantWithApiKey();

        $this->withToken($apiKey)->getJson('/api/v1/payments')->assertOk()->assertJsonMissingPath('key_hash');
        $this->withToken($apiKey)->getJson('/api/v1/admin/merchants')->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $merchantAttributes
     * @return array{0: Merchant, 1: string}
     */
    private function merchantWithApiKey(array $merchantAttributes = []): array
    {
        $expiresAt = $merchantAttributes['expires_at'] ?? null;
        unset($merchantAttributes['expires_at']);

        $merchant = Merchant::factory()->create($merchantAttributes);
        MerchantPaymentConfig::factory()->for($merchant)->create([
            'partner_id' => 'partner-test',
            'callback_url' => 'https://merchant.example/return',
            'enabled' => true,
            'encrypted_credentials' => [
                'merchant_id' => 'merchant-test',
                'private_key' => 'test-private-key',
                'dana_webhook_public_key' => 'verified-dana-public-key',
                'origin' => 'merchant.example',
                'channel_id' => '95221',
                'notification_url' => 'https://merchant.example/api/v1/webhooks/dana',
                'mcc' => '5814',
            ],
        ]);
        $result = app(MerchantApiKeyService::class)->create($merchant, 'feature-test', $expiresAt, ['payments:*']);

        return [$merchant, $result['api_key']];
    }

    /** @return array{merchant_order_id: string, amount_minor: int, currency: string, metadata: array<string, string>} */
    private function paymentPayload(): array
    {
        return ['merchant_order_id' => 'order-001', 'amount_minor' => 10_000, 'currency' => 'IDR', 'metadata' => ['invoice' => 'INV-001']];
    }
}
