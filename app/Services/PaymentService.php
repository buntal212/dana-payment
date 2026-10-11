<?php

namespace App\Services;

use App\Models\Merchant;
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

                $payment = Payment::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'merchant_id' => $merchant->getKey(),
                    'merchant_order_id' => $attributes['merchant_order_id'],
                    'provider' => 'dana',
                    'environment' => 'sandbox',
                    'amount_minor' => $attributes['amount_minor'],
                    'currency' => $attributes['currency'],
                    'status' => 'pending',
                    'idempotency_key' => $attributes['idempotency_key'],
                    'metadata' => $attributes['metadata'] ?? null,
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
        } catch (QueryException) {
            $existing = Payment::query()
                ->forMerchant($merchant)
                ->where(function ($query) use ($attributes): void {
                    $query->where('merchant_order_id', $attributes['merchant_order_id'])
                        ->orWhere('idempotency_key', $attributes['idempotency_key']);
                })
                ->first();

            if ($existing !== null && $existing->idempotency_key === $attributes['idempotency_key'] && $this->hasSamePayload($existing, $attributes)) {
                return $existing;
            }

            throw new ConflictHttpException('merchant_order_id atau Idempotency-Key sudah digunakan.');
        }
    }

    /** @param array{merchant_order_id: string, amount_minor: int, currency: string, metadata?: array<string, mixed>} $attributes */
    private function hasSamePayload(Payment $payment, array $attributes): bool
    {
        return $payment->merchant_order_id === $attributes['merchant_order_id']
            && $payment->amount_minor === $attributes['amount_minor']
            && $payment->currency === $attributes['currency']
            && $this->canonicalize($payment->metadata) === $this->canonicalize($attributes['metadata'] ?? null);
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
