<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class DanaSandboxIpgCashierUatService
{
    private const string IpgCreateOrderPath = '/rest/redirection/v1.0/debit/payment-host-to-host';
    private const string DefaultCashierProductCode = '51051000100000000001';

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
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, received: array{http_status: int, response_code: string|null, response_message: string, reference_no: string|null, web_redirect_url: string|null, request_id: string|null}, request: array<string, mixed>}
     */
    public function createOrderSuccess(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('UAT IPG Cashier Pay hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(15);
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
                'productCode' => self::DefaultCashierProductCode,
                'supportDeepLinkCheckoutUrl' => 'true',
            ],
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::IpgCreateOrderPath, $body, $timestamp, $credentials->privateKey);

        Log::debug('UAT DANA Cashier Pay Create Order Request.', [
            'endpoint' => self::IpgCreateOrderPath,
            'partner_reference_no' => $reference,
            'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'product_code' => self::DefaultCashierProductCode,
            'support_deep_link' => 'true',
        ]);

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

        Log::debug('UAT DANA Cashier Pay Create Order Response.', [
            'http_status' => $response->status(),
            'response_code' => $responseCode,
            'passed' => $passed,
            'request_id' => $requestId,
        ]);

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
                'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
                'support_deep_link_checkout_url' => 'true',
                'product_code' => self::DefaultCashierProductCode,
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
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, received: array{http_status: int, response_code: string|null, response_message: string, request_id: string|null}, request: array<string, mixed>}
     */
    public function missingTimestamp(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('UAT IPG Cashier Pay hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(15);
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
                'productCode' => self::DefaultCashierProductCode,
                'supportDeepLinkCheckoutUrl' => 'true',
            ],
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::IpgCreateOrderPath, $body, $timestamp, $credentials->privateKey);

        Log::debug('UAT DANA Cashier Pay Missing Timestamp Request.', [
            'endpoint' => self::IpgCreateOrderPath,
            'partner_reference_no' => $reference,
            'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'product_code' => self::DefaultCashierProductCode,
            'support_deep_link' => 'true',
        ]);

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

        Log::debug('UAT DANA Cashier Pay Missing Timestamp Response.', [
            'http_status' => $response->status(),
            'response_code' => $responseCode,
            'passed' => $passed,
            'request_id' => $requestId,
        ]);

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
                'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
                'support_deep_link_checkout_url' => 'true',
                'product_code' => self::DefaultCashierProductCode,
                'order_title' => 'UAT IPG Missing Timestamp',
                'scenario' => 'REDIRECT',
                'partner_id' => $credentials->partnerId,
                'channel_id' => $credentials->channelId,
                'origin' => $credentials->origin,
            ],
        ];
    }

    /**
     * Executes an official, transient Sandbox IPG Cashier Pay request with amount 505400.00
     * to simulate General Error. Expects responseCode 5005400 and responseMessage "General Error".
     * It never creates a local Payment or Invoice record.
     *
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, received: array{http_status: int, response_code: string|null, response_message: string, request_id: string|null}, request: array<string, mixed>}
     */
    public function generalError(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('UAT IPG Cashier Pay hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(15);
        $reference = (string) Str::ulid();
        $externalId = (string) Str::ulid();
        $payload = [
            'partnerReferenceNo' => $reference,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr(505400), 'currency' => 'IDR'],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => 'UAT IPG Cashier General Error', 'scenario' => 'REDIRECT'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
                'productCode' => self::DefaultCashierProductCode,
                'supportDeepLinkCheckoutUrl' => 'true',
            ],
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::IpgCreateOrderPath, $body, $timestamp, $credentials->privateKey);

        Log::debug('UAT DANA Cashier Pay General Error Request.', [
            'endpoint' => self::IpgCreateOrderPath,
            'partner_reference_no' => $reference,
            'amount_value' => '505400.00',
            'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'product_code' => self::DefaultCashierProductCode,
            'support_deep_link' => 'true',
        ]);

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
        $expectedCode = '5005400';
        $expectedMessage = 'General Error';
        $actualMessage = is_string($data['responseMessage'] ?? null)
            ? $data['responseMessage']
            : null;
        $passed = $responseCode === $expectedCode;
        $requestId = is_string($data['requestID'] ?? null)
            ? $data['requestID']
            : ($response->header('X-Request-ID') ?? $response->header('Request-Id'));

        Log::debug('UAT DANA Cashier Pay General Error Response.', [
            'http_status' => $response->status(),
            'response_code' => $responseCode,
            'passed' => $passed,
            'request_id' => $requestId,
        ]);

        return [
            'scenario' => 'ipg_create_order_general_error',
            'passed' => $passed,
            'expected' => ['response_code' => $expectedCode, 'response_message' => $expectedMessage],
            'received' => [
                'http_status' => $response->status(),
                'response_code' => $responseCode,
                'response_message' => $actualMessage ?? ($passed ? $expectedMessage : 'Respons DANA tidak sesuai ekspektasi UAT.'),
                'request_id' => is_string($requestId) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId) === 1 ? $requestId : null,
            ],
            'request' => [
                'endpoint' => self::IpgCreateOrderPath,
                'http_method' => 'POST',
                'x_timestamp_sent' => true,
                'partner_reference_no' => $reference,
                'amount_value' => $this->amountFormatter->formatIdr(505400),
                'currency' => 'IDR',
                'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
                'support_deep_link_checkout_url' => 'true',
                'product_code' => self::DefaultCashierProductCode,
                'order_title' => 'UAT IPG Cashier General Error',
                'scenario' => 'REDIRECT',
                'partner_id' => $credentials->partnerId,
                'channel_id' => $credentials->channelId,
                'origin' => $credentials->origin,
            ],
        ];
    }

    /**
     * Executes an official, transient Sandbox IPG Cashier Pay request with amount 435415.00
     * to simulate "Transaction is not permitted". Expects responseCode 4035415 and responseMessage "Transaction Not Permitted".
     * It never creates a local Payment or Invoice record.
     *
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, received: array{http_status: int, response_code: string|null, response_message: string, request_id: string|null}, request: array<string, mixed>}
     */
    public function transactionNotPermitted(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('UAT IPG Cashier Pay hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(15);
        $reference = (string) Str::ulid();
        $externalId = (string) Str::ulid();
        $payload = [
            'partnerReferenceNo' => $reference,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr(435415), 'currency' => 'IDR'],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => 'UAT IPG Cashier Not Permitted', 'scenario' => 'REDIRECT'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
                'productCode' => self::DefaultCashierProductCode,
                'supportDeepLinkCheckoutUrl' => 'true',
            ],
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::IpgCreateOrderPath, $body, $timestamp, $credentials->privateKey);

        Log::debug('UAT DANA Cashier Pay Transaction Not Permitted Request.', [
            'endpoint' => self::IpgCreateOrderPath,
            'partner_reference_no' => $reference,
            'amount_value' => '435415.00',
            'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'product_code' => self::DefaultCashierProductCode,
            'support_deep_link' => 'true',
        ]);

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
        $expectedCode = '4035415';
        $expectedMessage = 'Transaction Not Permitted';
        $actualMessage = is_string($data['responseMessage'] ?? null)
            ? $data['responseMessage']
            : null;
        $passed = $responseCode === $expectedCode;
        $requestId = is_string($data['requestID'] ?? null)
            ? $data['requestID']
            : ($response->header('X-Request-ID') ?? $response->header('Request-Id'));

        Log::debug('UAT DANA Cashier Pay Transaction Not Permitted Response.', [
            'http_status' => $response->status(),
            'response_code' => $responseCode,
            'passed' => $passed,
            'request_id' => $requestId,
        ]);

        return [
            'scenario' => 'ipg_create_order_transaction_not_permitted',
            'passed' => $passed,
            'expected' => ['response_code' => $expectedCode, 'response_message' => $expectedMessage],
            'received' => [
                'http_status' => $response->status(),
                'response_code' => $responseCode,
                'response_message' => $actualMessage ?? ($passed ? $expectedMessage : 'Respons DANA tidak sesuai ekspektasi UAT.'),
                'request_id' => is_string($requestId) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId) === 1 ? $requestId : null,
            ],
            'request' => [
                'endpoint' => self::IpgCreateOrderPath,
                'http_method' => 'POST',
                'x_timestamp_sent' => true,
                'partner_reference_no' => $reference,
                'amount_value' => $this->amountFormatter->formatIdr(435415),
                'currency' => 'IDR',
                'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
                'support_deep_link_checkout_url' => 'true',
                'product_code' => self::DefaultCashierProductCode,
                'order_title' => 'UAT IPG Cashier Not Permitted',
                'scenario' => 'REDIRECT',
                'partner_id' => $credentials->partnerId,
                'channel_id' => $credentials->channelId,
                'origin' => $credentials->origin,
            ],
        ];
    }

    /**
     * Executes an official, transient Sandbox IPG Cashier Pay request with amount 445408.00
     * to simulate "Merchant does not exist or status abnormal". Expects responseCode 4045408 and responseMessage "Invalid Merchant".
     * It never creates a local Payment or Invoice record.
     *
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, received: array{http_status: int, response_code: string|null, response_message: string, request_id: string|null}, request: array<string, mixed>}
     */
    public function invalidMerchant(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('UAT IPG Cashier Pay hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(15);
        $reference = (string) Str::ulid();
        $externalId = (string) Str::ulid();
        $payload = [
            'partnerReferenceNo' => $reference,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr(445408), 'currency' => 'IDR'],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => 'UAT IPG Cashier Invalid Merchant', 'scenario' => 'REDIRECT'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
                'productCode' => self::DefaultCashierProductCode,
                'supportDeepLinkCheckoutUrl' => 'true',
            ],
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::IpgCreateOrderPath, $body, $timestamp, $credentials->privateKey);

        Log::debug('UAT DANA Cashier Pay Invalid Merchant Request.', [
            'endpoint' => self::IpgCreateOrderPath,
            'partner_reference_no' => $reference,
            'amount_value' => '445408.00',
            'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'product_code' => self::DefaultCashierProductCode,
            'support_deep_link' => 'true',
        ]);

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
        $expectedCode = '4045408';
        $expectedMessage = 'Invalid Merchant';
        $actualMessage = is_string($data['responseMessage'] ?? null)
            ? $data['responseMessage']
            : null;
        $passed = $responseCode === $expectedCode;
        $requestId = is_string($data['requestID'] ?? null)
            ? $data['requestID']
            : ($response->header('X-Request-ID') ?? $response->header('Request-Id'));

        Log::debug('UAT DANA Cashier Pay Invalid Merchant Response.', [
            'http_status' => $response->status(),
            'response_code' => $responseCode,
            'passed' => $passed,
            'request_id' => $requestId,
        ]);

        return [
            'scenario' => 'ipg_create_order_invalid_merchant',
            'passed' => $passed,
            'expected' => ['response_code' => $expectedCode, 'response_message' => $expectedMessage],
            'received' => [
                'http_status' => $response->status(),
                'response_code' => $responseCode,
                'response_message' => $actualMessage ?? ($passed ? $expectedMessage : 'Respons DANA tidak sesuai ekspektasi UAT.'),
                'request_id' => is_string($requestId) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId) === 1 ? $requestId : null,
            ],
            'request' => [
                'endpoint' => self::IpgCreateOrderPath,
                'http_method' => 'POST',
                'x_timestamp_sent' => true,
                'partner_reference_no' => $reference,
                'amount_value' => $this->amountFormatter->formatIdr(445408),
                'currency' => 'IDR',
                'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
                'support_deep_link_checkout_url' => 'true',
                'product_code' => self::DefaultCashierProductCode,
                'order_title' => 'UAT IPG Cashier Invalid Merchant',
                'scenario' => 'REDIRECT',
                'partner_id' => $credentials->partnerId,
                'channel_id' => $credentials->channelId,
                'origin' => $credentials->origin,
            ],
        ];
    }

    /**
     * Executes two consecutive Sandbox IPG Cashier Pay requests with identical partnerReferenceNo
     * but different amounts (10000.00 IDR then 20000.00 IDR).
     * Request 1 expects 2005400 (Successful).
     * Request 2 expects 4045418 (Inconsistent Request).
     * If request 1 fails, the test stops and reports the failure without executing request 2.
     * Never creates local Payment or Invoice records.
     *
     * @return array{scenario: string, passed: bool, expected: array{response_code: string, response_message: string}, first_request: array<string, mixed>|null, second_request: array<string, mixed>|null, received: array{http_status: int, response_code: string|null, response_message: string, request_id: string|null}, request: array<string, mixed>}
     */
    public function inconsistentRequest(MerchantPaymentConfig $config): array
    {
        if ($config->environment !== 'sandbox' || $config->provider !== 'dana') {
            throw new \LogicException('UAT IPG Cashier Pay hanya tersedia untuk konfigurasi DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $reference = (string) Str::ulid();
        $expiresAt = now('Asia/Jakarta')->addMinutes(15);

        // --- Request 1: Amount 10000.00 IDR, Expects 2005400 ---
        $timestamp1 = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $externalId1 = (string) Str::ulid();
        $payload1 = [
            'partnerReferenceNo' => $reference,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr(10000), 'currency' => 'IDR'],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => 'UAT IPG Inconsistent Request 1', 'scenario' => 'REDIRECT'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
                'productCode' => self::DefaultCashierProductCode,
                'supportDeepLinkCheckoutUrl' => 'true',
            ],
        ];
        if ($credentials->subMerchantId !== null) {
            $payload1['subMerchantId'] = $credentials->subMerchantId;
        }

        $body1 = json_encode($payload1, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature1 = $this->signatureService->signTransactional('POST', self::IpgCreateOrderPath, $body1, $timestamp1, $credentials->privateKey);

        Log::debug('UAT DANA Cashier Pay Inconsistent Request 1.', [
            'endpoint' => self::IpgCreateOrderPath,
            'partner_reference_no' => $reference,
            'amount_value' => '10000.00',
        ]);

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
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::IpgCreateOrderPath);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('UAT IPG Cashier Pay DANA Sandbox request 1 tidak dapat dihubungi.', previous: $exception);
        }

        $data1 = $response1->json();
        $data1 = is_array($data1) ? $data1 : [];
        $responseCode1 = is_string($data1['responseCode'] ?? null) && preg_match('/\\A\\d{7}\\z/', $data1['responseCode']) === 1
            ? $data1['responseCode']
            : null;
        $actualMessage1 = is_string($data1['responseMessage'] ?? null) ? $data1['responseMessage'] : null;
        $firstPassed = $responseCode1 === '2005400';
        $requestId1 = is_string($data1['requestID'] ?? null)
            ? $data1['requestID']
            : ($response1->header('X-Request-ID') ?? $response1->header('Request-Id'));
        $referenceNo1 = is_string($data1['referenceNo'] ?? null) ? $data1['referenceNo'] : null;

        $firstRequestInfo = [
            'amount' => '10000.00',
            'http_status' => $response1->status(),
            'response_code' => $responseCode1,
            'response_message' => $actualMessage1 ?? ($firstPassed ? 'Successful' : 'Gagal'),
            'request_id' => is_string($requestId1) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId1) === 1 ? $requestId1 : null,
            'reference_no' => $referenceNo1,
        ];

        if (! $firstPassed) {
            Log::warning('UAT DANA Cashier Pay Inconsistent Request: request 1 gagal.', [
                'http_status' => $response1->status(),
                'response_code' => $responseCode1,
                'request_id' => $requestId1,
            ]);

            return [
                'scenario' => 'ipg_create_order_inconsistent_request',
                'passed' => false,
                'expected' => ['response_code' => '4045418', 'response_message' => 'Inconsistent Request'],
                'first_request' => $firstRequestInfo,
                'second_request' => null,
                'received' => [
                    'http_status' => $response1->status(),
                    'response_code' => $responseCode1,
                    'response_message' => 'Request pertama gagal ('.($responseCode1 ?? 'HTTP '.$response1->status()).'). Pengujian dihentikan.',
                    'request_id' => is_string($requestId1) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId1) === 1 ? $requestId1 : null,
                ],
                'request' => [
                    'endpoint' => self::IpgCreateOrderPath,
                    'partner_reference_no' => $reference,
                    'first_amount' => '10000.00',
                    'second_amount' => '20000.00',
                    'currency' => 'IDR',
                    'product_code' => self::DefaultCashierProductCode,
                    'support_deep_link_checkout_url' => 'true',
                ],
            ];
        }

        // --- Request 2: PartnerReferenceNo SAMA, Amount 20000.00 IDR, Expects 4045418 ---
        $timestamp2 = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $externalId2 = (string) Str::ulid();
        $payload2 = [
            'partnerReferenceNo' => $reference,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr(20000), 'currency' => 'IDR'],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => 'UAT IPG Inconsistent Request 2', 'scenario' => 'REDIRECT'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
                'productCode' => self::DefaultCashierProductCode,
                'supportDeepLinkCheckoutUrl' => 'true',
            ],
        ];
        if ($credentials->subMerchantId !== null) {
            $payload2['subMerchantId'] = $credentials->subMerchantId;
        }

        $body2 = json_encode($payload2, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature2 = $this->signatureService->signTransactional('POST', self::IpgCreateOrderPath, $body2, $timestamp2, $credentials->privateKey);

        Log::debug('UAT DANA Cashier Pay Inconsistent Request 2.', [
            'endpoint' => self::IpgCreateOrderPath,
            'partner_reference_no' => $reference,
            'amount_value' => '20000.00',
        ]);

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
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::IpgCreateOrderPath);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('UAT IPG Cashier Pay DANA Sandbox request 2 tidak dapat dihubungi.', previous: $exception);
        }

        $data2 = $response2->json();
        $data2 = is_array($data2) ? $data2 : [];
        $responseCode2 = is_string($data2['responseCode'] ?? null) && preg_match('/\\A\\d{7}\\z/', $data2['responseCode']) === 1
            ? $data2['responseCode']
            : null;
        $expectedCode = '4045418';
        $expectedMessage = 'Inconsistent Request';
        $actualMessage2 = is_string($data2['responseMessage'] ?? null) ? $data2['responseMessage'] : null;
        $secondPassed = $responseCode2 === $expectedCode;
        $requestId2 = is_string($data2['requestID'] ?? null)
            ? $data2['requestID']
            : ($response2->header('X-Request-ID') ?? $response2->header('Request-Id'));

        Log::debug('UAT DANA Cashier Pay Inconsistent Request 2 Response.', [
            'http_status' => $response2->status(),
            'response_code' => $responseCode2,
            'passed' => $secondPassed,
            'request_id' => $requestId2,
        ]);

        return [
            'scenario' => 'ipg_create_order_inconsistent_request',
            'passed' => $secondPassed,
            'expected' => ['response_code' => $expectedCode, 'response_message' => $expectedMessage],
            'first_request' => $firstRequestInfo,
            'second_request' => [
                'amount' => '20000.00',
                'http_status' => $response2->status(),
                'response_code' => $responseCode2,
                'response_message' => $actualMessage2 ?? ($secondPassed ? $expectedMessage : 'Respons DANA tidak sesuai ekspektasi UAT.'),
                'request_id' => is_string($requestId2) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId2) === 1 ? $requestId2 : null,
            ],
            'received' => [
                'http_status' => $response2->status(),
                'response_code' => $responseCode2,
                'response_message' => $actualMessage2 ?? ($secondPassed ? $expectedMessage : 'Respons DANA tidak sesuai ekspektasi UAT.'),
                'request_id' => is_string($requestId2) && preg_match('/\\A[A-Za-z0-9._-]{1,128}\\z/', $requestId2) === 1 ? $requestId2 : null,
            ],
            'request' => [
                'endpoint' => self::IpgCreateOrderPath,
                'http_method' => 'POST',
                'partner_reference_no' => $reference,
                'first_amount' => '10000.00',
                'second_amount' => '20000.00',
                'currency' => 'IDR',
                'valid_up_to' => $expiresAt->format('Y-m-d\\TH:i:sP'),
                'support_deep_link_checkout_url' => 'true',
                'product_code' => self::DefaultCashierProductCode,
                'partner_id' => $credentials->partnerId,
                'channel_id' => $credentials->channelId,
                'origin' => $credentials->origin,
            ],
        ];
    }
}
