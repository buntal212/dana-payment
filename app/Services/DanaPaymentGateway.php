<?php

namespace App\Services;

use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DanaPaymentGateway implements PaymentGatewayInterface
{
    private const CREATE_ORDER_PATH = '/payment-gateway/v1.0/debit/payment-host-to-host.htm';

    public function __construct(
        private DanaCredentialResolver $credentialResolver,
        private DanaSnapSignatureService $signatureService,
        private DanaAmountFormatter $amountFormatter,
    ) {}

    /** @return array{provider_order_id: string, checkout_url: string, expires_at: \DateTimeInterface} */
    public function startCheckout(Payment $payment, MerchantPaymentConfig $config): array
    {
        $credentials = $this->credentialResolver->resolve($config);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(30);
        $payload = [
            'partnerReferenceNo' => $payment->public_id,
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr($payment->amount_minor), 'currency' => $payment->currency],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $credentials->returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => mb_substr('Payment '.$payment->merchant_order_id, 0, 64), 'scenario' => 'REDIRECT'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
            ],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::CREATE_ORDER_PATH, $body, $timestamp, $credentials->privateKey);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.dana.timeout', 8))
                ->connectTimeout((int) config('services.dana.connect_timeout', 3))
                ->withHeaders([
                    'X-TIMESTAMP' => $timestamp,
                    'X-SIGNATURE' => $signature,
                    'ORIGIN' => $credentials->origin,
                    'X-PARTNER-ID' => $credentials->partnerId,
                    'X-EXTERNAL-ID' => $payment->public_id,
                    'CHANNEL-ID' => $credentials->channelId,
                ])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::CREATE_ORDER_PATH);
        } catch (ConnectionException $exception) {
            Log::warning('DANA Create Order tidak dapat dihubungi.', ['payment_id' => $payment->getKey(), 'merchant_id' => $payment->merchant_id]);

            throw new RuntimeException('Checkout DANA sementara tidak tersedia.', previous: $exception);
        }

        $responseBody = $response->json();
        if (! $response->successful() || ! is_array($responseBody) || ($responseBody['responseCode'] ?? null) !== '2005400' || ! is_string($responseBody['referenceNo'] ?? null) || ! is_string($responseBody['webRedirectUrl'] ?? null)) {
            Log::warning('DANA Create Order ditolak atau merespons tidak lengkap.', [
                'payment_id' => $payment->getKey(),
                'merchant_id' => $payment->merchant_id,
                'http_status' => $response->status(),
                'response_code' => is_array($responseBody) ? ($responseBody['responseCode'] ?? null) : null,
            ]);

            throw new RuntimeException('Checkout DANA belum dapat dibuat.');
        }

        return ['provider_order_id' => $responseBody['referenceNo'], 'checkout_url' => $responseBody['webRedirectUrl'], 'expires_at' => $expiresAt];
    }
}
