<?php

namespace App\Services;

use App\Enums\DanaSandboxPaymentMethod;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Query Payment is intentionally diagnostic only. It must never mutate Payment
 * because paid remains exclusively derived from a verified Finish Notify.
 */
class DanaSandboxQueryPaymentService
{
    private const QUERY_PAYMENT_PATH = '/payment-gateway/v1.0/debit/status.htm';

    public function __construct(
        private DanaCredentialResolver $credentialResolver,
        private DanaSnapSignatureService $signatureService,
    ) {}

    /** @return array<string, int|string|null> */
    public function query(Payment $payment, MerchantPaymentConfig $config): array
    {
        if ($payment->environment !== 'sandbox' || $payment->provider !== 'dana') {
            throw new \LogicException('Query Payment hanya tersedia untuk pembayaran DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $method = is_array($payment->metadata) ? ($payment->metadata['udev_sandbox_payment_method'] ?? null) : null;
        if ($method === DanaSandboxPaymentMethod::Qris->value && $credentials->externalStoreId === null) {
            throw new \LogicException('QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.');
        }

        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $payload = [
            'originalPartnerReferenceNo' => $payment->partner_reference_no ?? $payment->public_id,
            'serviceCode' => '54',
            'merchantId' => $credentials->merchantId,
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }
        if ($method === DanaSandboxPaymentMethod::Qris->value) {
            $payload['externalStoreId'] = $credentials->externalStoreId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::QUERY_PAYMENT_PATH, $body, $timestamp, $credentials->privateKey);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.dana.timeout', 8))
                ->connectTimeout((int) config('services.dana.connect_timeout', 3))
                ->withHeaders([
                    'X-TIMESTAMP' => $timestamp,
                    'X-SIGNATURE' => $signature,
                    'ORIGIN' => $credentials->origin,
                    'X-PARTNER-ID' => $credentials->partnerId,
                    'X-EXTERNAL-ID' => 'status-'.Str::ulid(),
                    'CHANNEL-ID' => $credentials->channelId,
                ])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::QUERY_PAYMENT_PATH);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Query Payment DANA Sandbox sementara tidak tersedia.', previous: $exception);
        }

        $responseBody = $response->json();
        $bodyData = is_array($responseBody) ? $responseBody : [];

        return [
            'http_status' => $response->status(),
            'response_code' => $this->stringOrNull($bodyData['responseCode'] ?? null),
            'latest_transaction_status' => $this->stringOrNull($bodyData['latestTransactionStatus'] ?? null),
            'request_id' => $this->stringOrNull($bodyData['requestID'] ?? null)
                ?? $this->stringOrNull($response->header('X-Request-ID'))
                ?? $this->stringOrNull($response->header('Request-Id')),
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 150) : null;
    }
}
