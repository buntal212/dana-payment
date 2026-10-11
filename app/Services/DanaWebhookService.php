<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DanaWebhookService
{
    public function __construct(
        private DanaCredentialResolver $credentialResolver,
        private DanaSnapSignatureService $signatureService,
    ) {}

    /** @param array<string, string|null> $headers */
    public function process(string $rawBody, array $headers): void
    {
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->recordRejected(null, $rawBody, 'invalid_json');

            throw new RuntimeException('Webhook DANA tidak valid.');
        }

        if (! is_array($payload) || ! is_string($payload['originalPartnerReferenceNo'] ?? null)) {
            $this->recordRejected(null, $rawBody, 'missing_reference');

            throw new RuntimeException('Webhook DANA tidak valid.');
        }

        $payment = Payment::query()->where('provider', 'dana')->where('public_id', $payload['originalPartnerReferenceNo'])->first();
        if ($payment === null) {
            $this->recordRejected(null, $rawBody, 'payment_not_found');

            throw new RuntimeException('Webhook DANA tidak valid.');
        }

        $config = MerchantPaymentConfig::query()
            ->where('merchant_id', $payment->merchant_id)
            ->where('provider', 'dana')
            ->where('environment', $payment->environment)
            ->where('enabled', true)
            ->first();
        if ($config === null) {
            $this->recordRejected($payment, $rawBody, 'config_not_found');

            throw new RuntimeException('Webhook DANA tidak dapat diproses.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = $headers['x-timestamp'] ?? null;
        $signature = $headers['x-signature'] ?? null;
        $partnerId = $headers['x-partner-id'] ?? null;
        $merchantId = $payload['merchantId'] ?? null;

        if (! is_string($timestamp) || ! is_string($signature) || $partnerId !== $credentials->partnerId || $merchantId !== $credentials->merchantId || ! $this->signatureService->verifyTransactional('POST', (string) config('services.dana.webhook_relative_path'), $this->signatureService->minifyJson($rawBody), $timestamp, $signature, $credentials->danaPublicKey)) {
            $this->recordRejected($payment, $rawBody, 'signature_or_identity_invalid');

            throw new RuntimeException('Webhook DANA tidak valid.');
        }

        $this->validatePaymentPayload($payment, $payload, $rawBody);
        $this->applyPaymentStatus($payment, $payload, $rawBody);
    }

    /** @param array<string, mixed> $payload */
    private function validatePaymentPayload(Payment $payment, array $payload, string $rawBody): void
    {
        $referenceNo = $payload['originalReferenceNo'] ?? null;
        $amount = $payload['amount'] ?? null;
        if (! is_string($referenceNo) || ! is_array($amount) || ! is_string($amount['value'] ?? null) || ! is_string($amount['currency'] ?? null) || $amount['value'] !== $payment->amount_minor.'.00' || $amount['currency'] !== $payment->currency || ($payment->provider_order_id !== null && $payment->provider_order_id !== $referenceNo)) {
            $this->recordRejected($payment, $rawBody, 'payment_data_mismatch');

            throw new RuntimeException('Webhook DANA tidak valid.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function applyPaymentStatus(Payment $payment, array $payload, string $rawBody): void
    {
        $providerReference = $payload['originalReferenceNo'];
        $targetStatus = match ($payload['latestTransactionStatus'] ?? null) {
            '00' => 'paid',
            '05' => 'expired',
            default => null,
        };

        if ($targetStatus === null) {
            $this->recordRejected($payment, $rawBody, 'unsupported_status');

            throw new RuntimeException('Webhook DANA tidak valid.');
        }

        DB::transaction(function () use ($payment, $providerReference, $rawBody, $targetStatus): void {
            $webhook = PaymentWebhook::query()->firstOrCreate(
                ['provider' => 'dana', 'environment' => $payment->environment, 'provider_event_id' => $providerReference],
                [
                    'merchant_id' => $payment->merchant_id,
                    'payment_id' => $payment->getKey(),
                    'payload_sha256' => hash('sha256', $rawBody),
                    'signature_valid' => true,
                    'processing_status' => 'received',
                    'attempts' => 0,
                ],
            );

            if (! $webhook->wasRecentlyCreated) {
                return;
            }

            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
            if ($lockedPayment->status !== 'pending' && $lockedPayment->status !== $targetStatus) {
                $webhook->update(['processing_status' => 'rejected', 'attempts' => 1, 'processed_at' => now(), 'error_code' => 'illegal_status_transition']);

                Log::warning('Webhook DANA memiliki transisi status ilegal.', ['payment_id' => $lockedPayment->getKey(), 'target_status' => $targetStatus]);

                return;
            }

            if ($lockedPayment->status === 'pending') {
                $updates = ['status' => $targetStatus, 'provider_order_id' => $providerReference];
                if ($targetStatus === 'paid') {
                    $updates['paid_at'] = now();
                }
                $lockedPayment->update($updates);

                PaymentEvent::query()->create([
                    'merchant_id' => $lockedPayment->merchant_id,
                    'payment_id' => $lockedPayment->getKey(),
                    'event_type' => 'payment.status_changed',
                    'old_status' => 'pending',
                    'new_status' => $targetStatus,
                    'source' => 'provider',
                    'safe_details' => ['provider' => 'dana', 'provider_order_id' => $providerReference],
                ]);
            }

            $webhook->update(['processing_status' => 'processed', 'attempts' => 1, 'processed_at' => now()]);
        });
    }

    private function recordRejected(?Payment $payment, string $rawBody, string $errorCode): void
    {
        PaymentWebhook::query()->create([
            'merchant_id' => $payment?->merchant_id,
            'payment_id' => $payment?->getKey(),
            'provider' => 'dana',
            'environment' => $payment?->environment ?? 'sandbox',
            'payload_sha256' => hash('sha256', $rawBody),
            'provider_event_id' => null,
            'signature_valid' => false,
            'processing_status' => 'rejected',
            'attempts' => 1,
            'processed_at' => now(),
            'error_code' => $errorCode,
        ]);

        Log::warning('Webhook DANA ditolak.', ['payment_id' => $payment?->getKey(), 'error_code' => $errorCode]);
    }
}
