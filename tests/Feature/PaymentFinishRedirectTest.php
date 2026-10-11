<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentFinishRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_page_shows_verification_message_without_changing_payment_status(): void
    {
        $payment = Payment::factory()->create(['status' => 'pending']);

        $this->get('/payment/finish?status=SUCCESS&reference='.$payment->public_id)
            ->assertOk()
            ->assertSee('Pembayaran sedang diverifikasi')
            ->assertSee('konfirmasi aman dari penyedia pembayaran')
            ->assertDontSee('Pembayaran berhasil');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_payment_finish_route_is_named_for_safe_return_url_generation(): void
    {
        $this->assertSame(url('/payment/finish'), route('payment.finish'));
    }
}
