<?php

namespace App\Services;

use App\Models\BillingWebhookOutbox;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BillingWebhookForwarder
{
    public function queueForwarding(Payment $payment, string $targetStatus): ?BillingWebhookOutbox
    {
        // Hanya proses pembayaran yang berasal dari UDEV Billing (fokus UDEV, jangan sentuh Larissama/eksternal)
        if (! $this->isUdevBillingPayment($payment)) {
            return null;
        }

        $targetUrl = (string) config('services.udev_billing.webhook_url');
        if (trim($targetUrl) === '' || ! filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            Log::info('Forwarding webhook UDEV Billing dilewati: target URL belum dikonfigurasi.', [
                'payment_id' => $payment->getKey(),
                'environment' => $payment->environment,
            ]);

            return null;
        }

        $eventType = match ($targetStatus) {
            'paid' => 'payment.paid',
            'expired' => 'payment.expired',
            default => 'payment.status_changed',
        };

        $idempotencyKey = 'billing-forward:'.$payment->public_id.':'.$targetStatus;

        $payload = [
            'merchant_order_id' => $payment->merchant_order_id,
            'id' => $payment->public_id,
            'originalReferenceNo' => $payment->public_id,
            'partner_reference_no' => $payment->partner_reference_no,
            'originalPartnerReferenceNo' => $payment->partner_reference_no ?? $payment->public_id,
            'partnerReferenceNo' => $payment->partner_reference_no ?? $payment->public_id,
            'provider_order_id' => $payment->provider_order_id,
            'status' => $targetStatus,
            'latestTransactionStatus' => match ($targetStatus) {
                'paid' => '00',
                'expired' => '05',
                default => $targetStatus,
            },
            'amount_minor' => (int) $payment->amount_minor,
            'currency' => (string) $payment->currency,
            'environment' => (string) $payment->environment,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];

        if (Schema::hasTable('billing_webhook_outboxes')) {
            $maxAttempts = (int) config('services.udev_billing.max_attempts', 5);

            $outbox = BillingWebhookOutbox::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'payment_id' => $payment->getKey(),
                    'environment' => $payment->environment,
                    'event_type' => $eventType,
                    'target_url' => $targetUrl,
                    'payload' => $payload,
                    'status' => BillingWebhookOutbox::StatusPending,
                    'attempts' => 0,
                    'max_attempts' => $maxAttempts,
                    'available_at' => now(),
                ]
            );

            // Eksekusi HTTP strictly di luar transaksi DB melalui afterCommit
            DB::afterCommit(function () use ($outbox): void {
                $this->dispatch($outbox->id);
            });

            return $outbox;
        }

        // Fallback jika migration outbox belum dijalankan
        DB::afterCommit(function () use ($payment, $targetUrl, $payload, $idempotencyKey, $eventType): void {
            $this->sendDirectly($payment, $targetUrl, $payload, $idempotencyKey, $eventType);
        });

        return null;
    }

    public function dispatch(int $outboxId): bool
    {
        if (! Schema::hasTable('billing_webhook_outboxes')) {
            return false;
        }

        $outbox = DB::transaction(function () use ($outboxId): ?BillingWebhookOutbox {
            $record = BillingWebhookOutbox::query()->lockForUpdate()->find($outboxId);
            if ($record === null || $record->status === BillingWebhookOutbox::StatusDelivered) {
                return null;
            }

            if ($record->status === BillingWebhookOutbox::StatusProcessing && $record->locked_at?->greaterThan(now()->subMinute())) {
                return null;
            }

            if ($record->available_at !== null && $record->available_at->isFuture()) {
                return null;
            }

            $record->update([
                'status' => BillingWebhookOutbox::StatusProcessing,
                'locked_at' => now(),
                'attempts' => $record->attempts + 1,
            ]);

            return $record->fresh();
        });

        if ($outbox === null) {
            return false;
        }

        $secret = (string) config('services.udev_billing.webhook_secret');
        $timeout = (int) config('services.udev_billing.timeout', 10);
        $connectTimeout = (int) config('services.udev_billing.connect_timeout', 3);
        $baseBackoff = (int) config('services.udev_billing.retry_backoff_seconds', 15);

        try {
            $response = Http::acceptJson()
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withHeaders(array_filter([
                    'X-Webhook-Secret' => $secret,
                    'Idempotency-Key' => $outbox->idempotency_key,
                ]))
                ->post($outbox->target_url, $outbox->payload);

            return $this->handleResponse($outbox, $response, $baseBackoff);
        } catch (ConnectionException $exception) {
            return $this->handleFailure($outbox, 'network_timeout', null, true, $baseBackoff);
        } catch (Throwable $exception) {
            return $this->handleFailure($outbox, 'dispatch_exception', null, true, $baseBackoff);
        }
    }

    public function dispatchPending(int $limit = 25): int
    {
        if (! Schema::hasTable('billing_webhook_outboxes')) {
            return 0;
        }

        $limit = max(1, min(100, $limit));

        $ids = BillingWebhookOutbox::query()
            ->where('status', BillingWebhookOutbox::StatusPending)
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            if ($this->dispatch($id)) {
                $count++;
            }
        }

        return $count;
    }

    public function isUdevBillingPayment(Payment $payment): bool
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];

        return ! empty($metadata['udev_invoice_id'])
            || ! empty($metadata['udev_payment_attempt_id'])
            || str_starts_with((string) $payment->merchant_order_id, 'INV-');
    }

    private function handleResponse(BillingWebhookOutbox $outbox, Response $response, int $baseBackoff): bool
    {
        $status = $response->status();

        if ($response->successful()) {
            $outbox->update([
                'status' => BillingWebhookOutbox::StatusDelivered,
                'delivered_at' => now(),
                'locked_at' => null,
                'available_at' => null,
                'last_error' => null,
                'last_http_status' => $status,
            ]);

            Log::info('Forwarding webhook ke UDEV Billing berhasil.', [
                'payment_id' => $outbox->payment_id,
                'environment' => $outbox->environment,
                'event_type' => $outbox->event_type,
                'attempts' => $outbox->attempts,
                'http_status' => $status,
            ]);

            return true;
        }

        $isClientError = $status >= 400 && $status < 500 && $status !== 429;
        $isRetryable = ! $isClientError;

        return $this->handleFailure(
            $outbox,
            $isClientError ? 'billing_rejected_client_error' : 'billing_server_error',
            $status,
            $isRetryable,
            $baseBackoff,
        );
    }

    private function handleFailure(
        BillingWebhookOutbox $outbox,
        string $errorCode,
        ?int $httpStatus,
        bool $retryable,
        int $baseBackoff,
    ): bool {
        $canRetry = $retryable && $outbox->attempts < $outbox->max_attempts;

        if ($canRetry) {
            $backoffSeconds = min(900, (int) ($baseBackoff * (2 ** max(0, $outbox->attempts - 1))));

            $outbox->update([
                'status' => BillingWebhookOutbox::StatusPending,
                'locked_at' => null,
                'available_at' => now()->addSeconds($backoffSeconds),
                'last_error' => $errorCode,
                'last_http_status' => $httpStatus,
            ]);

            Log::warning('Forwarding webhook ke UDEV Billing gagal sementara, dijadwalkan retry.', [
                'payment_id' => $outbox->payment_id,
                'environment' => $outbox->environment,
                'event_type' => $outbox->event_type,
                'attempts' => $outbox->attempts,
                'retry_after_seconds' => $backoffSeconds,
                'http_status' => $httpStatus,
                'error_code' => $errorCode,
            ]);

            return false;
        }

        $outbox->update([
            'status' => BillingWebhookOutbox::StatusFailed,
            'locked_at' => null,
            'available_at' => null,
            'last_error' => $errorCode,
            'last_http_status' => $httpStatus,
        ]);

        Log::error('Forwarding webhook ke UDEV Billing gagal permanen.', [
            'payment_id' => $outbox->payment_id,
            'environment' => $outbox->environment,
            'event_type' => $outbox->event_type,
            'attempts' => $outbox->attempts,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
        ]);

        return false;
    }

    /**
     * Fallback pengiriman langsung jika tabel outbox belum ada di database.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sendDirectly(Payment $payment, string $targetUrl, array $payload, string $idempotencyKey, string $eventType): void
    {
        $secret = (string) config('services.udev_billing.webhook_secret');
        $timeout = (int) config('services.udev_billing.timeout', 10);
        $connectTimeout = (int) config('services.udev_billing.connect_timeout', 3);

        try {
            $response = Http::acceptJson()
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withHeaders(array_filter([
                    'X-Webhook-Secret' => $secret,
                    'Idempotency-Key' => $idempotencyKey,
                ]))
                ->post($targetUrl, $payload);

            if ($response->successful()) {
                Log::info('Forwarding webhook langsung ke UDEV Billing berhasil.', [
                    'payment_id' => $payment->getKey(),
                    'environment' => $payment->environment,
                    'event_type' => $eventType,
                    'http_status' => $response->status(),
                ]);
            } else {
                Log::warning('Forwarding webhook langsung ke UDEV Billing gagal.', [
                    'payment_id' => $payment->getKey(),
                    'environment' => $payment->environment,
                    'event_type' => $eventType,
                    'http_status' => $response->status(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Forwarding webhook langsung ke UDEV Billing mengalami koneksi error.', [
                'payment_id' => $payment->getKey(),
                'environment' => $payment->environment,
                'event_type' => $eventType,
                'error' => 'connection_failed',
            ]);
        }
    }
}
