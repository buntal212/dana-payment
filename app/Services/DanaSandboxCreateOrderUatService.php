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
}
