<?php

namespace Tests\Feature;

use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAuditApiTest extends TestCase
{
    use RefreshDatabase;

    private const string ServiceToken = 'test-payment-audit-token';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.udev_internal', [
            'enabled' => true,
            'service_id' => 'udev-billing',
            'token' => self::ServiceToken,
            'abilities' => ['payments:audit:read'],
        ]);
        $this->app['url']->forceScheme('https');
    }

    public function test_returns_sandbox_audit_timeline_and_redacted_webhook_metadata_only(): void
    {
        $merchant = $this->managedMerchant();
        $payment = Payment::factory()->for($merchant)->create([
            'provider' => 'dana', 'environment' => 'sandbox', 'status' => 'paid',
            'merchant_order_id' => 'UDEV-ORDER-1', 'provider_order_id' => 'DANA-ORDER-1', 'paid_at' => now(),
        ]);
        PaymentEvent::factory()->for($merchant)->for($payment)->create([
            'event_type' => 'payment.status_changed', 'old_status' => 'pending', 'new_status' => 'paid', 'source' => 'provider',
        ]);
        PaymentWebhook::factory()->for($merchant)->for($payment)->create([
            'signature_valid' => true, 'processing_status' => 'processed', 'processed_at' => now(), 'error_code' => null,
        ]);

        $this->withToken(self::ServiceToken)
            ->getJson($this->auditUrl($merchant, $payment))
            ->assertOk()
            ->assertJsonPath('data.id', $payment->public_id)
            ->assertJsonPath('data.gateway_verified_at', $payment->paid_at->toIso8601String())
            ->assertJsonPath('data.timeline.0.old_status', 'pending')
            ->assertJsonMissingPath('data.checkout_url')
            ->assertJsonMissingPath('data.metadata');

        $this->withToken(self::ServiceToken)
            ->getJson("/api/v1/internal/merchants/{$merchant->id}/payments/{$payment->public_id}/webhooks")
            ->assertOk()
            ->assertJsonPath('data.0.signature_valid', true)
            ->assertJsonMissingPath('data.0.payload_sha256')
            ->assertJsonMissingPath('data.0.is_duplicate');
    }

    public function test_audit_is_merchant_isolated_and_requires_dedicated_ability(): void
    {
        $merchant = $this->managedMerchant();
        $otherMerchant = $this->managedMerchant();
        $otherPayment = Payment::factory()->for($otherMerchant)->create(['provider' => 'dana', 'environment' => 'sandbox']);

        $this->withToken(self::ServiceToken)->getJson($this->auditUrl($merchant, $otherPayment))->assertNotFound();
        config()->set('services.udev_internal.abilities', ['merchants:credentials:read']);
        $this->withToken(self::ServiceToken)->getJson("/api/v1/internal/merchants/{$merchant->id}/payment-audit")->assertForbidden();
    }

    public function test_lists_and_summarizes_only_filtered_sandbox_dana_payments(): void
    {
        $merchant = $this->managedMerchant();
        $paid = Payment::factory()->for($merchant)->create(['provider' => 'dana', 'environment' => 'sandbox', 'status' => 'paid', 'amount_minor' => 12000, 'currency' => 'IDR', 'merchant_order_id' => 'REF-PAID']);
        Payment::factory()->for($merchant)->create(['provider' => 'dana', 'environment' => 'sandbox', 'status' => 'pending', 'amount_minor' => 9000, 'currency' => 'IDR', 'merchant_order_id' => 'REF-PENDING']);
        Payment::factory()->for($merchant)->create(['provider' => 'dana', 'environment' => 'production', 'status' => 'paid', 'amount_minor' => 99999]);

        $this->withToken(self::ServiceToken)
            ->getJson("/api/v1/internal/merchants/{$merchant->id}/payment-audit?status=paid&merchant_order_id=REF-PAID")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $paid->public_id);
        $this->withToken(self::ServiceToken)
            ->getJson("/api/v1/internal/merchants/{$merchant->id}/payment-audit/summary")
            ->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.paid', 1)->assertJsonPath('data.paid_amounts.0.paid_amount_minor', 12000);
    }

    public function test_returns_checkout_url_and_payment_instruction_when_present_on_pending_sandbox_payment(): void
    {
        $merchant = $this->managedMerchant();
        $payment = Payment::factory()->for($merchant)->create([
            'provider' => 'dana',
            'environment' => 'sandbox',
            'status' => 'pending',
            'merchant_order_id' => 'UDEV-ORDER-PENDING',
            'checkout_url' => 'https://checkout.sandbox.dana.id/session/test123',
            'expires_at' => now()->addMinutes(15),
            'metadata' => [
                'udev_sandbox_payment_method' => 'qris',
                'udev_sandbox_payment_instruction' => [
                    'type' => 'qris_mpm',
                    'method' => 'qris',
                    'label' => 'QRIS',
                    'message' => 'Pindai QRIS menggunakan aplikasi DANA atau aplikasi pembayaran pendukung QRIS.',
                    'qr_content' => '00020101021226590014ID.DANA.WWW01189360091800000000005204581253033605802ID5911UDUMBARA DEV6007BANDUNG61054011562200716000000000000000000006304ABCD',
                    'payment_code' => '00020101021226590014ID.DANA.WWW01189360091800000000005204581253033605802ID5911UDUMBARA DEV6007BANDUNG61054011562200716000000000000000000006304ABCD',
                ],
            ],
        ]);

        $this->withToken(self::ServiceToken)
            ->getJson($this->auditUrl($merchant, $payment))
            ->assertOk()
            ->assertJsonPath('data.id', $payment->public_id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.checkout_available', true)
            ->assertJsonPath('data.checkout_url', 'https://checkout.sandbox.dana.id/session/test123')
            ->assertJsonPath('data.payment_instruction.type', 'qris_mpm')
            ->assertJsonPath('data.payment_instruction.qr_content', '00020101021226590014ID.DANA.WWW01189360091800000000005204581253033605802ID5911UDUMBARA DEV6007BANDUNG61054011562200716000000000000000000006304ABCD')
            ->assertJsonPath('data.expires_at', $payment->expires_at->toIso8601String());
    }

    private function managedMerchant(): Merchant
    {
        $merchant = Merchant::factory()->create();
        InternalMerchantProvisioningRequest::factory()->for($merchant)->create(['service_id' => 'udev-billing', 'environment' => 'sandbox']);
        return $merchant;
    }

    private function auditUrl(Merchant $merchant, Payment $payment): string
    {
        return "/api/v1/internal/merchants/{$merchant->id}/payments/{$payment->public_id}/audit";
    }
}
