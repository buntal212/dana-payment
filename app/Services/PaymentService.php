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
    public function __construct(private DanaSandboxQueryPaymentService $queryPaymentService) {}

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
                $isQris = (isset($attributes['payment_method']) && $attributes['payment_method'] === 'qris')
                    || (is_array($metadata) && (
                        ($metadata['payment_method'] ?? null) === 'qris'
                        || ($metadata['udev_sandbox_payment_method'] ?? null) === 'qris'
                    ));
                $environment = ! empty($attributes['environment']) ? (string) $attributes['environment'] : 'sandbox';
                $payment = Payment::query()->create([
                    'public_id' => $publicId,
                    // QRIS Create Order membatasi partnerReferenceNo menjadi 25
                    // karakter. Nilai ini dipersisten sebelum request pertama agar
                    // retry idempoten dan webhook/query tetap menunjuk payment sama.
                    'partner_reference_no' => $isQris ? 'Q'.substr(hash('sha256', $publicId), 0, 24) : null,
                    'merchant_id' => $merchant->getKey(),
                    'merchant_order_id' => $attributes['merchant_order_id'],
                    'provider' => 'dana',
                    'environment' => $environment,
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

    /** @param array{merchant_order_id: string, amount_minor: int, currency: string, metadata?: array<string, mixed>, idempotency_key: string, environment?: string|null} $attributes */
    public function createHostedCheckout(Merchant $merchant, array $attributes, PaymentGatewayInterface $gateway): Payment
    {
        $payment = $this->createPending($merchant, $attributes);

        $environment = $payment->environment;

        $config = MerchantPaymentConfig::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('provider', 'dana')
            ->where('environment', $environment)
            ->where('enabled', true)
            ->first();

        if ($config === null) {
            $envLabel = ucfirst($environment);
            throw new \LogicException("Konfigurasi DANA {$envLabel} belum tersedia untuk merchant ini.");
        }

        if ($payment->environment !== $config->environment) {
            $paymentEnv = ucfirst((string) $payment->environment);
            $configEnv = ucfirst((string) $config->environment);
            throw new \LogicException("Environment Payment ({$paymentEnv}) tidak cocok dengan konfigurasi DANA merchant ({$configLabel}).");
        }

        if ($this->hasCompleteCheckout($payment)) {
            return $payment;
        }

        // Hanya payment yang sudah pernah ada dan belum memiliki instruksi
        // checkout lengkap yang dipulihkan. Query Payment bersifat read-only;
        // ia tidak pernah mengubah status paid lokal.
        if (! $payment->wasRecentlyCreated) {
            $this->reconcilePartialCheckout($merchant, $payment, $config);
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

            if ($this->hasCompleteCheckout($lockedPayment)) {
                return $lockedPayment;
            }

            if ($lockedPayment->provider_order_id !== null
                && $lockedPayment->provider_order_id !== $checkout['provider_order_id']) {
                throw new \RuntimeException('Referensi order provider checkout tidak cocok.');
            }

            $lockedPayment->update([
                'provider_order_id' => $checkout['provider_order_id'],
                'checkout_url' => $checkout['checkout_url'],
                'expires_at' => $checkout['expires_at'],
                'metadata' => [
                    ...(is_array($lockedPayment->metadata) ? $lockedPayment->metadata : []),
                    'payment_instruction' => $checkout['payment_instruction'],
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

    private function reconcilePartialCheckout(Merchant $merchant, Payment $payment, MerchantPaymentConfig $config): void
    {
        try {
            $providerStatus = $this->queryPaymentService->query($payment, $config);
        } catch (\LogicException|\RuntimeException $exception) {
            PaymentEvent::query()->create([
                'merchant_id' => $merchant->getKey(),
                'payment_id' => $payment->getKey(),
                'event_type' => 'payment.checkout_recovery_failed',
                'old_status' => 'pending',
                'new_status' => 'pending',
                'source' => 'provider',
                'safe_details' => ['provider' => 'dana', 'stage' => 'query_payment'],
            ]);

            throw new \RuntimeException('Status order DANA belum dapat direkonsiliasi sebelum recovery checkout.', previous: $exception);
        }

        PaymentEvent::query()->create([
            'merchant_id' => $merchant->getKey(),
            'payment_id' => $payment->getKey(),
            'event_type' => 'payment.checkout_recovery_checked',
            'old_status' => 'pending',
            'new_status' => 'pending',
            'source' => 'provider',
            'safe_details' => [
                'provider' => 'dana',
                'http_status' => $providerStatus['http_status'] ?? null,
                'response_code' => $providerStatus['response_code'] ?? null,
                'latest_transaction_status' => $providerStatus['latest_transaction_status'] ?? null,
                'request_id' => $providerStatus['request_id'] ?? null,
            ],
        ]);

        $responseCode = $providerStatus['response_code'] ?? null;
        $latestStatus = $providerStatus['latest_transaction_status'] ?? null;

        // Status paid tetap hanya dapat diterapkan oleh Finish Notify yang
        // tervalidasi. Recovery tidak boleh membuat order baru ketika DANA
        // sudah melaporkan transaksi berhasil.
        if ($responseCode === '2005500' && $latestStatus === '00') {
            throw new \RuntimeException('DANA telah melaporkan pembayaran berhasil; menunggu Finish Notify terverifikasi.');
        }

        // Transaksi ditemukan di DANA namun belum dibayar (misal status pending 01, expired/cancelled 05, dll).
        // Pembuatan order baru diblokir untuk mencegah order ganda pada DANA.
        if ($responseCode === '2005500') {
            $statusLabel = $latestStatus !== null ? (string) $latestStatus : 'tidak diketahui';
            throw new \RuntimeException("DANA melaporkan transaksi sudah terdaftar dengan status {$statusLabel}; pembuatan order baru diblokir.");
        }

        // Fail-closed recovery: Create Order HANYA boleh dilanjutkan jika Query Payment
        // secara eksplisit mengonfirmasi 4045501 (Transaction Not Found).
        // Semua status transaksi ditemukan, Paid, Pending, error, timeout, atau respons tidak dikenal wajib memblokir Create Order.
        if ($responseCode !== '4045501') {
            $codeLabel = $responseCode !== null ? (string) $responseCode : 'tidak diketahui';
            throw new \RuntimeException("Status order DANA tidak mengonfirmasi transaksi belum dibuat (responseCode: {$codeLabel}); pembuatan order baru diblokir demi keamanan.");
        }
    }

    private function hasCompleteCheckout(Payment $payment): bool
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];
        $instruction = is_array($metadata['payment_instruction'] ?? null)
            ? $metadata['payment_instruction']
            : (is_array($metadata['udev_sandbox_payment_instruction'] ?? null)
                ? $metadata['udev_sandbox_payment_instruction']
                : []);
        $paymentCode = $instruction['payment_code'] ?? null;

        return $payment->expires_at !== null
            && ((is_string($payment->checkout_url) && filter_var($payment->checkout_url, FILTER_VALIDATE_URL))
                || (is_string($paymentCode) && $paymentCode !== ''));
    }

    /** @param array{merchant_order_id: string, amount_minor: int, currency: string, metadata?: array<string, mixed>, environment?: string|null} $attributes */
    private function hasSamePayload(Payment $payment, array $attributes): bool
    {
        $expectedEnv = ! empty($attributes['environment']) ? (string) $attributes['environment'] : 'sandbox';

        return $payment->merchant_order_id === $attributes['merchant_order_id']
            && $payment->amount_minor === $attributes['amount_minor']
            && $payment->currency === $attributes['currency']
            && $payment->environment === $expectedEnv
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

        unset($metadata['payment_instruction'], $metadata['udev_sandbox_payment_instruction']);

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
