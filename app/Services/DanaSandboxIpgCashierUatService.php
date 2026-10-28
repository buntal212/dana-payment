<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class DanaSandboxIpgCashierUatService
{
    private const string IpgCreateOrderPath = '/rest/redirection/v1.0/debit/payment-host-to-host';

    public function __construct(
        private readonly DanaCredentialResolver $credentialResolver,
        private readonly DanaSnapSignatureService $signatureService,
        private readonly DanaAmountFormatter $amountFormatter,
    ) {}

    /**
     * Executes an official, transient Sandbox IPG Cashier Pay Create Order request
     * to /rest/redirection/v1.0/debit/payment-host-to-host. It never creates a local
     * Payment or Invoice record.
     *
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, received: array{http_status: int, response_code: string|null, response_message: string, reference_no: string|null, web_redirect_url: string|null, request_id: string|null}, request: array{endpoint: string, x_timestamp_sent: bool}}
     */
    public function createOrderSuccess(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('UAT IPG Cashier Pay hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(30);
        $reference = (string) Str::ulid();
        $externalId = (string) Str::ulid();
        $payload = [
            'partnerReferenceNo' => $reference,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr(10000), 'currency' => 'IDR'],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => 'UAT IPG Cashier Success', 'scenario' => 'REDIRECT'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
            ],
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::IpgCreateOrderPath, $body, $timestamp, $credentials->privateKey);

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
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::IpgCreateOrderPath);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('UAT IPG Cashier Pay DANA Sandbox tidak dapat dihubungi.', previous: $exception);
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $responseCode = is_string($data['responseCode'] ?? null) && preg_match('/\\A\\d{7}\\z/', $data['responseCode']) === 1
            ? $data['responseCode']
            : null;
        $actualMessage = is_string($data['responseMessage'] ?? null)
            ? $data['responseMessage']
            : null;
        $passed = $responseCode === '2005400';
        $requestId = is_string($data['requestID'] ?? null)
            ? $data['requestID']
            : ($response->header('X-Request-ID') ?? $response->header('Request-Id'));
        $referenceNo = is_string($data['referenceNo'] ?? null) ? $data['referenceNo'] : null;
        $webRedirectUrl = is_string($data['webRedirectUrl'] ?? null) ? $data['webRedirectUrl'] : null;

        return [
            'scenario' => 'ipg_create_order_success',
            'passed' => $passed,
            'expected' => ['response_code' => '2005400', 'response_message' => 'Successful'],
            'received' => [
                'http_status' => $response->status(),
                'response_code' => $responseCode,
                'response_message' => $actualMessage ?? ($passed ? 'Successful' : 'Respons DANA tidak sesuai ekspektasi UAT.'),
                'reference_no' => $referenceNo,
                'web_redirect_url' => $webRedirectUrl,
                'request_id' => is_string($requestId) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId) === 1 ? $requestId : null,
            ],
            'request' => [
                'endpoint' => self::IpgCreateOrderPath,
                'http_method' => 'POST',
                'x_timestamp_sent' => true,
                'partner_reference_no' => $reference,
                'amount_value' => $this->amountFormatter->formatIdr(10000),
                'currency' => 'IDR',
                'order_title' => 'UAT IPG Cashier Success',
                'scenario' => 'REDIRECT',
                'partner_id' => $credentials->partnerId,
                'channel_id' => $credentials->channelId,
                'origin' => $credentials->origin,
            ],
        ];
    }

    /**
     * Executes an official, transient Sandbox IPG Cashier Pay request deliberately
     * omitting the X-TIMESTAMP header. Expects HTTP 400 and responseCode 4005402.
     *
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, received: array{http_status: int, response_code: string|null, response_message: string, request_id: string|null}, request: array{endpoint: string, x_timestamp_sent: bool}}
     */
    public function missingTimestamp(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('UAT IPG Cashier Pay hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(30);
        $reference = (string) Str::ulid();
        $externalId = (string) Str::ulid();
        $payload = [
            'partnerReferenceNo' => $reference,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr(10000), 'currency' => 'IDR'],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => 'UAT IPG Missing Timestamp', 'scenario' => 'REDIRECT'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
            ],
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::IpgCreateOrderPath, $body, $timestamp, $credentials->privateKey);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.dana.timeout', 8))
                ->connectTimeout((int) config('services.dana.connect_timeout', 3))
                ->withHeaders([
                    // X-TIMESTAMP sengaja dihilangkan untuk UAT 4005402 IPG
                    'X-SIGNATURE' => $signature,
                    'ORIGIN' => $credentials->origin,
                    'X-PARTNER-ID' => $credentials->partnerId,
                    'X-EXTERNAL-ID' => $externalId,
                    'CHANNEL-ID' => $credentials->channelId,
                ])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::IpgCreateOrderPath);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('UAT IPG Cashier Pay DANA Sandbox tidak dapat dihubungi.', previous: $exception);
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $responseCode = is_string($data['responseCode'] ?? null) && preg_match('/\\A\\d{7}\\z/', $data['responseCode']) === 1
            ? $data['responseCode']
            : null;
        $expectedMessage = 'Invalid Mandatory Field X-TIMESTAMP';
        $actualMessage = is_string($data['responseMessage'] ?? null)
            ? $data['responseMessage']
            : null;
        $passed = $responseCode === '4005402' && $actualMessage === $expectedMessage;
        $requestId = is_string($data['requestID'] ?? null)
            ? $data['requestID']
            : ($response->header('X-Request-ID') ?? $response->header('Request-Id'));

        return [
            'scenario' => 'ipg_create_order_missing_x_timestamp',
            'passed' => $passed,
            'expected' => ['response_code' => '4005402', 'response_message' => $expectedMessage],
            'received' => [
                'http_status' => $response->status(),
                'response_code' => $responseCode,
                'response_message' => $passed ? $expectedMessage : ($actualMessage ?? 'Respons DANA tidak sesuai ekspektasi UAT.'),
                'request_id' => is_string($requestId) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId) === 1 ? $requestId : null,
            ],
            'request' => [
                'endpoint' => self::IpgCreateOrderPath,
                'http_method' => 'POST',
                'x_timestamp_sent' => false,
                'partner_reference_no' => $reference,
                'amount_value' => $this->amountFormatter->formatIdr(10000),
                'currency' => 'IDR',
                'order_title' => 'UAT IPG Missing Timestamp',
                'scenario' => 'REDIRECT',
                'partner_id' => $credentials->partnerId,
                'channel_id' => $credentials->channelId,
                'origin' => $credentials->origin,
            ],
        ];
    }
}
