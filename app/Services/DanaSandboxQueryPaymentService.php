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

    public function resolveBaseUrl(?string $environment = null): string
    {
        $env = strtolower(trim((string) $environment));

        if ($env === 'production') {
            $configured = config('services.dana.base_urls.production');
            if (is_string($configured) && $configured !== '' && $configured !== 'https://api.dana.id') {
                return $configured;
            }

            return 'https://api.saas.dana.id';
        }

        return (string) (config('services.dana.base_urls.sandbox')
            ?: config('services.dana.base_url')
            ?: 'https://api.sandbox.dana.id');
    }

    /** @return array<string, int|string|null> */
    public function query(Payment $payment, MerchantPaymentConfig $config): array
    {
        if ($payment->provider !== 'dana') {
            throw new \LogicException('Query Payment hanya tersedia untuk pembayaran DANA.');
        }

        if (! in_array($payment->environment, ['sandbox', 'production'], true)) {
            $envLabel = ucfirst((string) $payment->environment);
            throw new \LogicException("Query Payment tidak tersedia untuk environment DANA {$envLabel}.");
        }

        if ($config->environment !== $payment->environment) {
            $envLabel = ucfirst((string) $payment->environment);
            throw new \LogicException("Query Payment memerlukan konfigurasi DANA {$envLabel}.");
        }

        $credentials = $this->credentialResolver->resolve($config);
        $method = is_array($payment->metadata) ? ($payment->metadata['payment_method'] ?? $payment->metadata['udev_sandbox_payment_method'] ?? null) : null;
        if ($method === DanaSandboxPaymentMethod::Qris->value && $credentials->externalStoreId === null) {
            $envLabel = ucfirst((string) $payment->environment);
            throw new \LogicException("QRIS {$envLabel} memerlukan External Store ID pada konfigurasi merchant.");
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
        $baseUrl = $this->resolveBaseUrl($payment->environment);

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
                ->post(rtrim($baseUrl, '/').self::QUERY_PAYMENT_PATH);
        } catch (ConnectionException $exception) {
            $envLabel = ucfirst((string) $payment->environment);
            throw new RuntimeException("Query Payment DANA {$envLabel} sementara tidak tersedia.", previous: $exception);
        }

        $responseBody = $response->json();
        $bodyData = is_array($responseBody) ? $responseBody : [];

        return [
            'http_status' => $response->status(),
            'response_code' => $this->stringOrNull($bodyData['responseCode'] ?? null),
            'response_message' => $this->stringOrNull($bodyData['responseMessage'] ?? null),
            'latest_transaction_status' => $this->stringOrNull($bodyData['latestTransactionStatus'] ?? null),
            'request_id' => $this->stringOrNull($bodyData['requestID'] ?? null)
                ?? $this->stringOrNull($response->header('X-Request-ID'))
                ?? $this->stringOrNull($response->header('Request-Id')),
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /** @return array{http_status: int, response_code: string|null, response_message: string|null, request_id: string|null, checked_at: string} */
    public function queryFictitiousReference(string $referenceNo, MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('Query Payment hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $payload = [
            'originalPartnerReferenceNo' => $referenceNo,
            'serviceCode' => '54',
            'merchantId' => $credentials->merchantId,
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
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
                    'X-EXTERNAL-ID' => 'status-uat-'.Str::ulid(),
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
            'response_message' => $this->stringOrNull($bodyData['responseMessage'] ?? null),
            'request_id' => $this->stringOrNull($bodyData['requestID'] ?? null)
                ?? $this->stringOrNull($response->header('X-Request-ID'))
                ?? $this->stringOrNull($response->header('Request-Id')),
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /** @return array{http_status: int, response_code: string|null, response_message: string|null, request_id: string|null, checked_at: string} */
    public function queryMissingTimestamp(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('Query Payment hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $reference = (string) Str::ulid();
        $payload = [
            'originalPartnerReferenceNo' => $reference,
            'serviceCode' => '54',
            'merchantId' => $credentials->merchantId,
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::QUERY_PAYMENT_PATH, $body, $timestamp, $credentials->privateKey);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.dana.timeout', 8))
                ->connectTimeout((int) config('services.dana.connect_timeout', 3))
                ->withHeaders([
                    // X-TIMESTAMP sengaja tidak dikirim untuk UAT 4005502
                    'X-SIGNATURE' => $signature,
                    'ORIGIN' => $credentials->origin,
                    'X-PARTNER-ID' => $credentials->partnerId,
                    'X-EXTERNAL-ID' => 'status-uat-'.Str::ulid(),
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
            'response_message' => $this->stringOrNull($bodyData['responseMessage'] ?? null),
            'request_id' => $this->stringOrNull($bodyData['requestID'] ?? null)
                ?? $this->stringOrNull($response->header('X-Request-ID'))
                ?? $this->stringOrNull($response->header('Request-Id')),
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /** @return array{http_status: int, response_code: string|null, response_message: string|null, request_id: string|null, checked_at: string} */
    public function queryInternalServerError(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('Query Payment hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $reference = (string) Str::ulid();
        $payload = [
            'originalPartnerReferenceNo' => $reference,
            'serviceCode' => 'AZ',
            'merchantId' => $credentials->merchantId,
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
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
                    'X-EXTERNAL-ID' => 'status-uat-'.Str::ulid(),
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
            'response_message' => $this->stringOrNull($bodyData['responseMessage'] ?? null),
            'request_id' => $this->stringOrNull($bodyData['requestID'] ?? null)
                ?? $this->stringOrNull($response->header('X-Request-ID'))
                ?? $this->stringOrNull($response->header('Request-Id')),
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /** @return array{http_status: int, response_code: string|null, response_message: string|null, request_id: string|null, checked_at: string} */
    public function queryInvalidSignature(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('Query Payment hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $reference = (string) Str::ulid();
        $payload = [
            'originalPartnerReferenceNo' => $reference,
            'serviceCode' => '54',
            'merchantId' => $credentials->merchantId,
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.dana.timeout', 8))
                ->connectTimeout((int) config('services.dana.connect_timeout', 3))
                ->withHeaders([
                    'X-TIMESTAMP' => $timestamp,
                    // X-SIGNATURE sengaja diisi invalid_signature untuk UAT 4015500
                    'X-SIGNATURE' => 'invalid_signature',
                    'ORIGIN' => $credentials->origin,
                    'X-PARTNER-ID' => $credentials->partnerId,
                    'X-EXTERNAL-ID' => 'status-uat-'.Str::ulid(),
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
            'response_message' => $this->stringOrNull($bodyData['responseMessage'] ?? null),
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
