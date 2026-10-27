<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class DanaSandboxCreateOrderUatService
{
    private const string CreateOrderPath = '/payment-gateway/v1.0/debit/payment-host-to-host.htm';

    public function __construct(
        private readonly DanaCredentialResolver $credentialResolver,
        private readonly DanaSnapSignatureService $signatureService,
        private readonly DanaAmountFormatter $amountFormatter,
    ) {}

    /**
     * Sends one intentionally-invalid, transient Sandbox request. It never
     * creates a local Payment and deliberately omits X-TIMESTAMP.
     *
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, received: array{http_status: int, response_code: string|null, response_message: string, request_id: string|null}, request: array{x_timestamp_sent: false}}
     */
    public function missingTimestamp(MerchantPaymentConfig $config): array
    {
        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(5);
        $reference = 'UAT-TS-'.Str::upper(Str::random(18));
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
                'order' => ['orderTitle' => 'UAT missing X-TIMESTAMP', 'scenario' => 'API'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::CreateOrderPath, $body, $timestamp, $credentials->privateKey);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.dana.timeout', 8))
                ->connectTimeout((int) config('services.dana.connect_timeout', 3))
                ->withHeaders([
                    // X-TIMESTAMP sengaja tidak dikirim untuk UAT 4005402.
                    'X-SIGNATURE' => $signature,
                    'ORIGIN' => $credentials->origin,
                    'X-PARTNER-ID' => $credentials->partnerId,
                    'X-EXTERNAL-ID' => $externalId,
                    'CHANNEL-ID' => $credentials->channelId,
                ])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::CreateOrderPath);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('UAT Create Order DANA Sandbox tidak dapat dihubungi.', previous: $exception);
        }

        $data = $response->json();
        $responseCode = is_array($data) && is_string($data['responseCode'] ?? null) && preg_match('/\\A\\d{7}\\z/', $data['responseCode']) === 1
            ? $data['responseCode']
            : null;
        $expectedMessage = 'Invalid Mandatory Field X-TIMESTAMP';
        $actualMessage = is_array($data) && is_string($data['responseMessage'] ?? null)
            ? $data['responseMessage']
            : null;
        $passed = $responseCode === '4005402' && $actualMessage === $expectedMessage;
        $requestId = $response->header('X-Request-ID') ?? $response->header('Request-Id');

        return [
            'scenario' => 'create_order_missing_x_timestamp',
            'passed' => $passed,
            'expected' => ['response_code' => '4005402', 'response_message' => $expectedMessage],
            'received' => [
                'http_status' => $response->status(),
                'response_code' => $responseCode,
                'response_message' => $passed ? $expectedMessage : 'Respons DANA tidak sesuai ekspektasi UAT.',
                'request_id' => is_string($requestId) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId) === 1 ? $requestId : null,
            ],
            'request' => ['x_timestamp_sent' => false],
        ];
    }

    /**
     * Sends one intentionally-invalid, transient Sandbox request with an invalid
     * amount format according to DANA contract. It never creates a local Payment
     * and expects responseCode 4005401 and responseMessage "Invalid Field Format amount".
     *
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, received: array{http_status: int, response_code: string|null, response_message: string, request_id: string|null}, request: array{amount_format_valid: false}}
     */
    public function invalidAmountFormat(MerchantPaymentConfig $config): array
    {
        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(5);
        $reference = 'UAT-AMT-'.Str::upper(Str::random(18));
        $externalId = (string) Str::ulid();
        $payload = [
            'partnerReferenceNo' => $reference,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => 'INVALID', 'currency' => 'IDR'],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => 'UAT invalid amount format', 'scenario' => 'API'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::CreateOrderPath, $body, $timestamp, $credentials->privateKey);

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
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::CreateOrderPath);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('UAT Create Order DANA Sandbox tidak dapat dihubungi.', previous: $exception);
        }

        $data = $response->json();
        $responseCode = is_array($data) && is_string($data['responseCode'] ?? null) && preg_match('/\\A\\d{7}\\z/', $data['responseCode']) === 1
            ? $data['responseCode']
            : null;
        $expectedMessage = 'Invalid Field Format amount';
        $actualMessage = is_array($data) && is_string($data['responseMessage'] ?? null)
            ? $data['responseMessage']
            : null;
        $passed = $responseCode === '4005401' && $actualMessage === $expectedMessage;
        $requestId = $response->header('X-Request-ID') ?? $response->header('Request-Id');

        return [
            'scenario' => 'create_order_invalid_amount_format',
            'passed' => $passed,
            'expected' => ['response_code' => '4005401', 'response_message' => $expectedMessage],
            'received' => [
                'http_status' => $response->status(),
                'response_code' => $responseCode,
                'response_message' => $passed ? $expectedMessage : 'Respons DANA tidak sesuai ekspektasi UAT.',
                'request_id' => is_string($requestId) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId) === 1 ? $requestId : null,
            ],
            'request' => ['amount_format_valid' => false],
        ];
    }

    /**
     * Sends two consecutive Sandbox Create Order requests with identical
     * partnerReferenceNo but different amounts (Rp100.000 then Rp200.000).
     * Request 1 expects 2005400 (success), and Request 2 expects 4045418 (Inconsistent Request).
     *
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, first_request: array<string, mixed>, second_request: array<string, mixed>, received: array<string, mixed>}
     */
    public function inconsistentRequest(MerchantPaymentConfig $config): array
    {
        $credentials = $this->credentialResolver->resolve($config);
        $reference = 'UAT-INC-'.Str::upper(Str::random(18));
        $expiresAt = now('Asia/Jakarta')->addMinutes(5);

        // --- Request 1: Nominal Rp100.000 (sukses) ---
        $timestamp1 = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $externalId1 = (string) Str::ulid();
        $payload1 = [
            'partnerReferenceNo' => $reference,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr(100000), 'currency' => 'IDR'],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => 'UAT inconsistent request (first)', 'scenario' => 'API'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
            ],
        ];
        $body1 = json_encode($payload1, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature1 = $this->signatureService->signTransactional('POST', self::CreateOrderPath, $body1, $timestamp1, $credentials->privateKey);

        try {
            $response1 = Http::acceptJson()
                ->timeout((int) config('services.dana.timeout', 8))
                ->connectTimeout((int) config('services.dana.connect_timeout', 3))
                ->withHeaders([
                    'X-TIMESTAMP' => $timestamp1,
                    'X-SIGNATURE' => $signature1,
                    'ORIGIN' => $credentials->origin,
                    'X-PARTNER-ID' => $credentials->partnerId,
                    'X-EXTERNAL-ID' => $externalId1,
                    'CHANNEL-ID' => $credentials->channelId,
                ])
                ->withBody($body1, 'application/json')
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::CreateOrderPath);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('UAT Create Order DANA Sandbox request 1 tidak dapat dihubungi.', previous: $exception);
        }

        $data1 = $response1->json();
        $firstResponseCode = is_array($data1) && is_string($data1['responseCode'] ?? null) && preg_match('/\\A\\d{7}\\z/', $data1['responseCode']) === 1
            ? $data1['responseCode']
            : null;
        $firstActualMessage = is_array($data1) && is_string($data1['responseMessage'] ?? null)
            ? $data1['responseMessage']
            : null;
        $firstPassed = $firstResponseCode === '2005400';
        $requestId1 = $response1->header('X-Request-ID') ?? $response1->header('Request-Id');

        // --- Request 2: PartnerReferenceNo IDENTIK, nominal Rp200.000 (Inconsistent Request 4045418) ---
        $timestamp2 = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $externalId2 = (string) Str::ulid();
        $payload2 = [
            'partnerReferenceNo' => $reference,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr(200000), 'currency' => 'IDR'],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => 'UAT inconsistent request (second)', 'scenario' => 'API'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
            ],
        ];
        $body2 = json_encode($payload2, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature2 = $this->signatureService->signTransactional('POST', self::CreateOrderPath, $body2, $timestamp2, $credentials->privateKey);

        try {
            $response2 = Http::acceptJson()
                ->timeout((int) config('services.dana.timeout', 8))
                ->connectTimeout((int) config('services.dana.connect_timeout', 3))
                ->withHeaders([
                    'X-TIMESTAMP' => $timestamp2,
                    'X-SIGNATURE' => $signature2,
                    'ORIGIN' => $credentials->origin,
                    'X-PARTNER-ID' => $credentials->partnerId,
                    'X-EXTERNAL-ID' => $externalId2,
                    'CHANNEL-ID' => $credentials->channelId,
                ])
                ->withBody($body2, 'application/json')
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::CreateOrderPath);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('UAT Create Order DANA Sandbox request 2 tidak dapat dihubungi.', previous: $exception);
        }

        $data2 = $response2->json();
        $secondResponseCode = is_array($data2) && is_string($data2['responseCode'] ?? null) && preg_match('/\\A\\d{7}\\z/', $data2['responseCode']) === 1
            ? $data2['responseCode']
            : null;
        $expectedMessage = 'Inconsistent Request';
        $secondActualMessage = is_array($data2) && is_string($data2['responseMessage'] ?? null)
            ? $data2['responseMessage']
            : null;
        $secondPassed = $secondResponseCode === '4045418' && $secondActualMessage === $expectedMessage;
        $passed = $firstPassed && $secondPassed;
        $requestId2 = $response2->header('X-Request-ID') ?? $response2->header('Request-Id');

        $firstRequestInfo = [
            'amount' => '100000.00',
            'http_status' => $response1->status(),
            'response_code' => $firstResponseCode,
            'response_message' => $firstPassed ? 'Successful' : ($firstActualMessage ?? 'Gagal membuat order pertama.'),
            'request_id' => is_string($requestId1) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId1) === 1 ? $requestId1 : null,
        ];

        $secondRequestInfo = [
            'amount' => '200000.00',
            'http_status' => $response2->status(),
            'response_code' => $secondResponseCode,
            'response_message' => $secondPassed ? $expectedMessage : ($secondActualMessage ?? 'Respons DANA tidak sesuai ekspektasi UAT.'),
            'request_id' => is_string($requestId2) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId2) === 1 ? $requestId2 : null,
        ];

        return [
            'scenario' => 'create_order_inconsistent_request',
            'passed' => $passed,
            'expected' => ['response_code' => '4045418', 'response_message' => $expectedMessage],
            'first_request' => $firstRequestInfo,
            'second_request' => $secondRequestInfo,
            'received' => $secondRequestInfo,
            'request' => [
                'partner_reference_no' => $reference,
                'first_amount' => '100000.00',
                'second_amount' => '200000.00',
            ],
        ];
    }
}
