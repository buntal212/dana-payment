<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentWebhook;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DanaWebhookService
{
    public function __construct(
        private DanaCredentialResolver $credentialResolver,
        private DanaSnapSignatureService $signatureService,
        private DanaAmountFormatter $amountFormatter,
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

        try {
            $credentials = $this->credentialResolver->resolve($config);
            $minifiedBody = $this->signatureService->minifyJson($rawBody);
        } catch (\LogicException|\JsonException) {
            $this->recordRejected($payment, $rawBody, 'config_or_payload_invalid');

            throw new RuntimeException('Webhook DANA tidak dapat diproses.');
        }
        $timestamp = $headers['x-timestamp'] ?? null;
        $signature = $headers['x-signature'] ?? null;
        $partnerId = $headers['x-partner-id'] ?? null;
        $merchantId = $payload['merchantId'] ?? null;

        $timestampValid = is_string($timestamp) && $this->isJakartaTimestamp($timestamp);
        $partnerIdValid = is_string($partnerId) && hash_equals($credentials->partnerId, $partnerId);
        $merchantIdValid = is_string($merchantId) && hash_equals($credentials->merchantId, $merchantId);
        $signatureValid = is_string($timestamp)
            && is_string($signature)
            && $this->signatureService->verifyTransactional(
                'POST',
                (string) config('services.dana.webhook_relative_path'),
                $minifiedBody,
                $timestamp,
                $signature,
                $credentials->danaPublicKey,
            );

        if (! $timestampValid || ! $partnerIdValid || ! $merchantIdValid || ! $signatureValid) {
            $this->logAuthenticationDiagnostics($payment, $timestampValid, $partnerIdValid, $merchantIdValid, $signatureValid);
            $this->recordRejected($payment, $rawBody, 'signature_or_identity_invalid');

            throw new RuntimeException('Webhook DANA tidak valid.');
        }

        $this->validatePaymentPayload($payment, $payload, $rawBody);

        if (! $this->applyPaymentStatus($payment, $payload, $rawBody)) {
            throw new RuntimeException('Webhook DANA tidak valid.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validatePaymentPayload(Payment $payment, array $payload, string $rawBody): void
    {
        $referenceNo = $payload['originalReferenceNo'] ?? null;
        $amount = $payload['amount'] ?? null;
        if (! is_string($referenceNo) || ! is_array($amount) || ! is_string($amount['value'] ?? null) || ! is_string($amount['currency'] ?? null) || ! $this->amountFormatter->matchesIdr($payment->amount_minor, $amount['value']) || $amount['currency'] !== $payment->currency || ($payment->provider_order_id !== null && $payment->provider_order_id !== $referenceNo)) {
            $this->recordRejected($payment, $rawBody, 'payment_data_mismatch');

            throw new RuntimeException('Webhook DANA tidak valid.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function applyPaymentStatus(Payment $payment, array $payload, string $rawBody): bool
    {
        $providerReference = $payload['originalReferenceNo'];
        $payloadHash = hash('sha256', $rawBody);
        $targetStatus = match ($payload['latestTransactionStatus'] ?? null) {
            '00' => 'paid',
            '05' => 'expired',
            default => null,
        };

        if ($targetStatus === null) {
            $this->recordRejected($payment, $rawBody, 'unsupported_status');

            throw new RuntimeException('Webhook DANA tidak valid.');
        }

        return DB::transaction(function () use ($payment, $providerReference, $payloadHash, $targetStatus): bool {
            // Kunci payment sebelum membaca fingerprint payload agar callback
            // paralel tidak dapat sama-sama menerapkan transisi status.
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            $webhook = PaymentWebhook::query()
                ->where('payment_id', $lockedPayment->getKey())
                ->where('provider', 'dana')
                ->where('environment', $lockedPayment->environment)
                ->where('payload_sha256', $payloadHash)
                ->where('processing_status', 'processed')
                ->lockForUpdate()
                ->first();
            if ($webhook !== null) {
                return true;
            }

            // Finish Notify mendokumentasikan referensi transaksi, bukan ID event.
            // provider_event_id lama dibiarkan nullable; deduplikasi memakai
            // payload bertanda tangan yang persis sama untuk payment ini.
            $webhook = PaymentWebhook::query()->create([
                'merchant_id' => $lockedPayment->merchant_id,
                'payment_id' => $lockedPayment->getKey(),
                'provider' => 'dana',
                'environment' => $lockedPayment->environment,
                'payload_sha256' => $payloadHash,
                'provider_event_id' => null,
                'signature_valid' => true,
                'processing_status' => 'received',
                'attempts' => 1,
            ]);

            if ($lockedPayment->status !== 'pending' && $lockedPayment->status !== $targetStatus) {
                $webhook->update(['processing_status' => 'rejected', 'attempts' => 1, 'processed_at' => now(), 'error_code' => 'illegal_status_transition']);

                Log::warning('Webhook DANA memiliki transisi status ilegal.', ['payment_id' => $lockedPayment->getKey(), 'target_status' => $targetStatus]);

                return false;
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

            return true;
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

    private function logAuthenticationDiagnostics(Payment $payment, bool $timestampValid, bool $partnerIdValid, bool $merchantIdValid, bool $signatureValid): void
    {
        Log::warning('Webhook DANA gagal validasi autentikasi.', [
            'payment_id' => $payment->getKey(),
            'payment_merchant_id' => $payment->merchant_id,
            'timestamp_valid' => $timestampValid,
            'partner_id_valid' => $partnerIdValid,
            'merchant_id_valid' => $merchantIdValid,
            'signature_valid' => $signatureValid,
        ]);
    }

    private function isJakartaTimestamp(string $timestamp): bool
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:sP', $timestamp);

        return $date !== false && $date->format('Y-m-d\\TH:i:sP') === $timestamp && $date->format('P') === '+07:00';
    }
}
