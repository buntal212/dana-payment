<?php

namespace App\Services;

use App\Enums\DanaSandboxPaymentMethod;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class DanaSandboxCancelPaymentService
{
    private const string CANCEL_PAYMENT_PATH = '/payment-gateway/v1.0/debit/cancel.htm';

    public function __construct(
        private DanaCredentialResolver $credentialResolver,
        private DanaSnapSignatureService $signatureService,
    ) {}

    /** @return array<string, mixed> */
    public function cancel(Payment $payment, MerchantPaymentConfig $config, ?string $reason = null): array
    {
        if ($payment->environment !== 'sandbox' || $payment->provider !== 'dana') {
            throw new \LogicException('Pembatalan transaksi hanya tersedia untuk pembayaran DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $method = is_array($payment->metadata) ? ($payment->metadata['udev_sandbox_payment_method'] ?? null) : null;
        if ($method === DanaSandboxPaymentMethod::Qris->value && $credentials->externalStoreId === null) {
            throw new \LogicException('QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.');
        }

        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $payload = [
            'originalPartnerReferenceNo' => $payment->partner_reference_no ?? $payment->public_id,
            'merchantId' => $credentials->merchantId,
            'reason' => $reason ?? 'Cancelled by merchant in Sandbox',
        ];

        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        if ($method === DanaSandboxPaymentMethod::Qris->value && $credentials->externalStoreId !== null) {
            $payload['externalStoreId'] = $credentials->externalStoreId;
        }

        if (is_array($payment->metadata) && ! empty($payment->metadata['dana_reference_no'])) {
            $payload['originalReferenceNo'] = (string) $payment->metadata['dana_reference_no'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::CANCEL_PAYMENT_PATH, $body, $timestamp, $credentials->privateKey);
        $externalId = 'cancel-'.Str::ulid();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.dana.timeout', 8))
                ->connectTimeout((int) config('services.dana.connect_timeout', 3))
                ->withHeaders([
                    'X-TIMESTAMP' => $timestamp,
                    'X-SIGNATURE' => $signature,
                    'ORIGIN' => $credentials->origin,
                    'X-PARTNER-ID' => $credentials->partnerId,
                    'X-EXTERNAL-ID' => $externalId,
                    'CHANNEL-ID' => $credentials->channelId,
                ])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::CANCEL_PAYMENT_PATH);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Layanan pembatalan DANA Sandbox sementara tidak tersedia.', previous: $exception);
        }

        $responseBody = $response->json();
        $bodyData = is_array($responseBody) ? $responseBody : [];

        return [
            'http_status' => $response->status(),
            'response_code' => $this->stringOrNull($bodyData['responseCode'] ?? null),
            'response_message' => $this->stringOrNull($bodyData['responseMessage'] ?? null),
            'cancel_time' => $this->stringOrNull($bodyData['cancelTime'] ?? null),
            'original_partner_reference_no' => $payload['originalPartnerReferenceNo'],
            'request_id' => $this->stringOrNull($bodyData['requestID'] ?? null)
                ?? $this->stringOrNull($response->header('X-Request-ID'))
                ?? $this->stringOrNull($response->header('Request-Id')),
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
            'raw_response' => $bodyData,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 150) : null;
    }
}
