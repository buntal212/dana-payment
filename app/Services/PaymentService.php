<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PaymentService
{
    /** @param array{merchant_order_id: string, amount_minor: int, currency: string, metadata?: array<string, mixed>, idempotency_key: string} $attributes */
    public function createPending(Merchant $merchant, array $attributes): Payment
    {
        try {
            return DB::transaction(function () use ($attributes, $merchant): Payment {
                $existing = Payment::query()
                    ->forMerchant($merchant)
                    ->where('idempotency_key', $attributes['idempotency_key'])
                    ->first();

                if ($existing !== null) {
                    if ($this->hasSamePayload($existing, $attributes)) {
                        return $existing;
                    }

                    throw new ConflictHttpException('Idempotency-Key sudah digunakan untuk payload yang berbeda.');
                }

                $publicId = (string) Str::ulid();
                $metadata = $attributes['metadata'] ?? null;
                $isQrisSandbox = is_array($metadata) && ($metadata['udev_sandbox_payment_method'] ?? null) === 'qris';
                $payment = Payment::query()->create([
                    'public_id' => $publicId,
                    // QRIS Create Order membatasi partnerReferenceNo menjadi 25
                    // karakter. Nilai ini dipersisten sebelum request pertama agar
                    // retry idempoten dan webhook/query tetap menunjuk payment sama.
                    'partner_reference_no' => $isQrisSandbox ? 'Q'.substr(hash('sha256', $publicId), 0, 24) : null,
                    'merchant_id' => $merchant->getKey(),
                    'merchant_order_id' => $attributes['merchant_order_id'],
                    'provider' => 'dana',
                    'environment' => 'sandbox',
                    'amount_minor' => $attributes['amount_minor'],
                    'currency' => $attributes['currency'],
                    'status' => 'pending',
                    'idempotency_key' => $attributes['idempotency_key'],
                    'metadata' => $metadata,
                ]);

                PaymentEvent::query()->create([
                    'merchant_id' => $merchant->getKey(),
                    'payment_id' => $payment->getKey(),
                    'event_type' => 'payment.created',
                    'new_status' => 'pending',
                    'source' => 'merchant',
                    'safe_details' => ['merchant_order_id' => $payment->merchant_order_id],
                ]);

                return $payment;
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = Payment::query()
                ->forMerchant($merchant)
                ->where(function ($query) use ($attributes): void {
                    $query->where('merchant_order_id', $attributes['merchant_order_id'])
                        ->orWhere('idempotency_key', $attributes['idempotency_key']);
                })
                ->first();

            if ($existing !== null) {
                if ($existing->idempotency_key === $attributes['idempotency_key']) {
                    if ($this->hasSamePayload($existing, $attributes)) {
                        return $existing;
                    }

                    throw new ConflictHttpException('Idempotency-Key sudah digunakan untuk payload yang berbeda.');
                }

                if ($existing->merchant_order_id === $attributes['merchant_order_id']) {
                    throw new ConflictHttpException('merchant_order_id sudah digunakan.');
                }
            }

            throw new ConflictHttpException('Konflik penyimpanan transaksi. Coba ulang dengan tindakan baru.');
        }
    }

    /** @param array{merchant_order_id: string, amount_minor: int, currency: string, metadata?: array<string, mixed>, idempotency_key: string} $attributes */
    public function createHostedCheckout(Merchant $merchant, array $attributes, PaymentGatewayInterface $gateway): Payment
    {
        $payment = $this->createPending($merchant, $attributes);

        if ($payment->provider_order_id !== null) {
            return $payment;
        }

        $config = MerchantPaymentConfig::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('provider', 'dana')
            ->where('environment', 'sandbox')
            ->where('enabled', true)
            ->first();

        if ($config === null) {
            throw new \LogicException('Konfigurasi DANA Sandbox belum tersedia untuk merchant ini.');
        }

        try {
            $checkout = $gateway->startCheckout($payment, $config);
        } catch (\RuntimeException $exception) {
            PaymentEvent::query()->create([
                'merchant_id' => $merchant->getKey(),
                'payment_id' => $payment->getKey(),
                'event_type' => 'payment.checkout_failed',
                'old_status' => 'pending',
                'new_status' => 'pending',
                'source' => 'provider',
                'safe_details' => ['provider' => 'dana'],
            ]);

            throw $exception;
        }

        return DB::transaction(function () use ($checkout, $merchant, $payment): Payment {
            $lockedPayment = Payment::query()->forMerchant($merchant)->lockForUpdate()->findOrFail($payment->getKey());

            if ($lockedPayment->checkout_url !== null) {
                return $lockedPayment;
            }

            $lockedPayment->update([
                'provider_order_id' => $checkout['provider_order_id'],
                'checkout_url' => $checkout['checkout_url'],
                'expires_at' => $checkout['expires_at'],
                'metadata' => [
                    ...(is_array($lockedPayment->metadata) ? $lockedPayment->metadata : []),
                    'udev_sandbox_payment_instruction' => $checkout['payment_instruction'],
                ],
            ]);

            PaymentEvent::query()->create([
                'merchant_id' => $merchant->getKey(),
                'payment_id' => $lockedPayment->getKey(),
                'event_type' => 'payment.checkout_created',
                'old_status' => 'pending',
                'new_status' => 'pending',
                'source' => 'provider',
                'safe_details' => ['provider' => 'dana', 'provider_order_id' => $checkout['provider_order_id']],
            ]);

            return $lockedPayment->refresh();
        });
    }

    /** @param array{merchant_order_id: string, amount_minor: int, currency: string, metadata?: array<string, mixed>} $attributes */
    private function hasSamePayload(Payment $payment, array $attributes): bool
    {
        return $payment->merchant_order_id === $attributes['merchant_order_id']
            && $payment->amount_minor === $attributes['amount_minor']
            && $payment->currency === $attributes['currency']
            // Metadata instruksi checkout ditambahkan oleh gateway setelah
            // request awal. Nilai internal itu bukan bagian payload merchant
            // sehingga tidak boleh membuat retry dengan Idempotency-Key sama
            // berubah menjadi konflik.
            && $this->canonicalize($this->requestMetadata($payment->metadata)) === $this->canonicalize($attributes['metadata'] ?? null);
    }

    private function requestMetadata(mixed $metadata): mixed
    {
        if (! is_array($metadata)) {
            return $metadata;
        }

        unset($metadata['udev_sandbox_payment_instruction']);

        return $metadata;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return is_string($sqlState) && str_starts_with($sqlState, '23');
    }

    private function canonicalize(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);

            foreach ($value as $key => $item) {
                $value[$key] = json_decode($this->canonicalize($item), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
