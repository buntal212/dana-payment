<?php

namespace App\Services;

use App\Enums\DanaSandboxPaymentMethod;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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

    /** @return array{provider_order_id: string, checkout_url: string|null, expires_at: \DateTimeInterface, payment_instruction: array<string, mixed>} */
    public function startCheckout(Payment $payment, MerchantPaymentConfig $config): array
    {
        $this->validateEnvironmentMatch($payment, $config);

        $credentials = $this->credentialResolver->resolve($config);
        $paymentMethod = $this->resolvePaymentMethod($payment);
        $environment = (string) ($config->environment ?: $payment->environment);

        return $paymentMethod === null
            ? $this->startHostedCheckout($payment, $credentials, $environment)
            : $this->startCustomCheckout($payment, $credentials, $paymentMethod, $environment);
    }

    public function resolveBaseUrl(?string $environment = null): string
    {
        $env = strtolower(trim((string) $environment));

        if ($env === 'production') {
            return (string) (config('services.dana.base_urls.production') ?: 'https://api.dana.id');
        }

        if ($env === 'sandbox') {
            return (string) (config('services.dana.base_urls.sandbox')
                ?: config('services.dana.base_url')
                ?: 'https://api.sandbox.dana.id');
        }

        return (string) (config("services.dana.base_urls.{$env}")
            ?: config('services.dana.base_url')
            ?: 'https://api.sandbox.dana.id');
    }

    /** @return array{provider_order_id: string, checkout_url: string|null, expires_at: \DateTimeInterface, payment_instruction: array<string, mixed>} */
    private function startHostedCheckout(Payment $payment, DanaCredentials $credentials, ?string $environment = null): array
    {
        $returnUrl = $this->returnUrl($payment, $credentials->returnUrl);
        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(30);
        $payload = [
            'partnerReferenceNo' => $this->partnerReferenceNo($payment),
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr($payment->amount_minor), 'currency' => $payment->currency],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $returnUrl, 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'additionalInfo' => [
                'order' => ['orderTitle' => mb_substr('Payment '.$payment->merchant_order_id, 0, 64), 'scenario' => 'REDIRECT'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
            ],
        ];
        // DANA menghitung hash dari body JSON yang diminify. Kirim representasi
        // kanonis yang sama dengan yang ditandatangani agar tidak ada perbedaan
        // escape slash/Unicode antara HTTP body dan string-to-sign.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::CREATE_ORDER_PATH, $body, $timestamp, $credentials->privateKey);
        $this->logRequestDiagnostics($payment, $body, $timestamp, $signature, $credentials->privateKey);
        $baseUrl = $this->resolveBaseUrl($environment);

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
                ->post(rtrim($baseUrl, '/').self::CREATE_ORDER_PATH);
        } catch (ConnectionException $exception) {
            Log::warning('DANA Create Order tidak dapat dihubungi.', ['payment_id' => $payment->getKey(), 'merchant_id' => $payment->merchant_id]);

            throw new RuntimeException('Checkout DANA sementara tidak tersedia.', previous: $exception);
        }

        $responseBody = $response->json();
        $this->logResponseDiagnostics($payment, $response, $responseBody);

        if (! $response->successful() || ! is_array($responseBody) || ($responseBody['responseCode'] ?? null) !== '2005400' || ! is_string($responseBody['referenceNo'] ?? null) || ! is_string($responseBody['webRedirectUrl'] ?? null)) {
            Log::warning('DANA Create Order ditolak atau merespons tidak lengkap.', [
                'payment_id' => $payment->getKey(),
                'merchant_id' => $payment->merchant_id,
                'http_status' => $response->status(),
                'response_code' => is_array($responseBody)
                    ? ($responseBody['responseCode'] ?? null)
                    : null,
                'request_id' => $response->header('X-Request-ID') ?? $response->header('Request-Id'),
            ]);

            throw new RuntimeException('Checkout DANA belum dapat dibuat.');
        }

        return [
            'provider_order_id' => $responseBody['referenceNo'],
            'checkout_url' => $responseBody['webRedirectUrl'],
            'expires_at' => $expiresAt,
            'payment_instruction' => [
                'method' => 'hosted_checkout',
                'label' => 'DANA Hosted Checkout',
                'type' => 'redirect',
                'message' => 'Lanjutkan pembayaran pada halaman checkout DANA.',
            ],
        ];
    }

    private function validateEnvironmentMatch(Payment $payment, MerchantPaymentConfig $config): void
    {
        $paymentEnv = strtolower(trim((string) $payment->environment));
        $configEnv = strtolower(trim((string) $config->environment));

        if ($paymentEnv !== '' && $configEnv !== '' && $paymentEnv !== $configEnv) {
            $paymentLabel = ucfirst($paymentEnv);
            $configLabel = ucfirst($configEnv);
            throw new \LogicException("Environment Payment ({$paymentLabel}) tidak cocok dengan konfigurasi DANA merchant ({$configLabel}).");
        }
    }

    /** @return array{provider_order_id: string, checkout_url: string|null, expires_at: \DateTimeInterface, payment_instruction: array<string, mixed>} */
    private function startCustomSandboxCheckout(Payment $payment, DanaCredentials $credentials, DanaSandboxPaymentMethod $paymentMethod, ?string $environment = null): array
    {
        return $this->startCustomCheckout($payment, $credentials, $paymentMethod, $environment);
    }

    /** @return array{provider_order_id: string, checkout_url: string|null, expires_at: \DateTimeInterface, payment_instruction: array<string, mixed>} */
    private function startCustomCheckout(Payment $payment, DanaCredentials $credentials, DanaSandboxPaymentMethod $paymentMethod, ?string $environment = null): array
    {
        $env = strtolower(trim((string) ($environment ?: $payment->environment ?: 'sandbox')));
        $isProduction = $env === 'production';
        $envLabel = $isProduction ? 'Production' : 'Sandbox';

        if ($paymentMethod->requiresExternalStore() && $credentials->externalStoreId === null) {
            throw new \LogicException("QRIS {$envLabel} memerlukan External Store ID pada konfigurasi merchant.");
        }

        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $expiresAt = now('Asia/Jakarta')->addMinutes(30);
        $orderPrefix = $isProduction ? 'Payment ' : 'Sandbox ';
        $payload = [
            'partnerReferenceNo' => $this->partnerReferenceNo($payment),
            'merchantId' => $credentials->merchantId,
            'amount' => ['value' => $this->amountFormatter->formatIdr($payment->amount_minor), 'currency' => $payment->currency],
            'validUpTo' => $expiresAt->format('Y-m-d\\TH:i:sP'),
            'urlParams' => [
                ['url' => $this->returnUrl($payment, $credentials->returnUrl), 'type' => 'PAY_RETURN', 'isDeeplink' => 'N'],
                ['url' => $credentials->notificationUrl, 'type' => 'NOTIFICATION', 'isDeeplink' => 'N'],
            ],
            'payOptionDetails' => [[
                'payMethod' => $paymentMethod->payMethod(),
                'payOption' => $paymentMethod->payOption(),
                'transAmount' => ['value' => $this->amountFormatter->formatIdr($payment->amount_minor), 'currency' => $payment->currency],
            ]],
            'additionalInfo' => [
                'order' => ['orderTitle' => mb_substr($orderPrefix.$payment->merchant_order_id, 0, 64), 'scenario' => 'API'],
                'mcc' => $credentials->mcc,
                'envInfo' => ['sourcePlatform' => 'IPG', 'terminalType' => 'SYSTEM'],
            ],
        ];
        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }
        if ($paymentMethod->requiresExternalStore()) {
            $payload['externalStoreId'] = $credentials->externalStoreId;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $this->signatureService->signTransactional('POST', self::CREATE_ORDER_PATH, $body, $timestamp, $credentials->privateKey);
        $this->logRequestDiagnostics($payment, $body, $timestamp, $signature, $credentials->privateKey);
        $baseUrl = $this->resolveBaseUrl($environment);

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
                ->post(rtrim($baseUrl, '/').self::CREATE_ORDER_PATH);
        } catch (ConnectionException $exception) {
            Log::warning("DANA Custom Checkout {$envLabel} tidak dapat dihubungi.", ['payment_id' => $payment->getKey(), 'merchant_id' => $payment->merchant_id]);

            throw new RuntimeException("Checkout DANA {$envLabel} sementara tidak tersedia.", previous: $exception);
        }

        $responseBody = $response->json();
        $this->logResponseDiagnostics($payment, $response, $responseBody);
        if (! $response->successful() || ! is_array($responseBody) || ($responseBody['responseCode'] ?? null) !== '2005400' || ! is_string($responseBody['referenceNo'] ?? null)) {
            Log::warning("DANA Custom Checkout {$envLabel} ditolak atau merespons tidak lengkap.", [
                'payment_id' => $payment->getKey(),
                'merchant_id' => $payment->merchant_id,
                'payment_method' => $paymentMethod->value,
                'http_status' => $response->status(),
                'response_code' => is_array($responseBody) ? ($responseBody['responseCode'] ?? null) : null,
                'request_id' => $response->header('X-Request-ID') ?? $response->header('Request-Id'),
            ]);

            throw new RuntimeException("Checkout DANA {$envLabel} belum dapat dibuat.");
        }

        $checkoutUrl = is_string($responseBody['webRedirectUrl'] ?? null) ? $responseBody['webRedirectUrl'] : null;
        $qrContent = $responseBody['additionalInfo']['qrContent'] ?? ($responseBody['qrContent'] ?? null);
        if (! is_string($qrContent) || trim($qrContent) === '') {
            $qrContent = null;
        }
        $paymentCode = $responseBody['additionalInfo']['paymentCode'] ?? null;
        if (! is_string($paymentCode) || trim($paymentCode) === '') {
            $paymentCode = $qrContent;
        }
        if ($paymentMethod === DanaSandboxPaymentMethod::DanaWallet && $checkoutUrl === null) {
            throw new RuntimeException("Respons DANA Wallet {$envLabel} tidak menyediakan checkout URL.");
        }
        if ($paymentMethod !== DanaSandboxPaymentMethod::DanaWallet && $paymentCode === null) {
            throw new RuntimeException("Respons QRIS atau Virtual Account {$envLabel} tidak menyediakan kode pembayaran.");
        }

        return [
            'provider_order_id' => $responseBody['referenceNo'],
            'checkout_url' => $checkoutUrl,
            'expires_at' => $expiresAt,
            'payment_instruction' => $this->paymentInstruction($paymentMethod, $paymentCode, $qrContent, $responseBody, $isProduction),
        ];
    }

    /** @param array<string, mixed> $responseBody @return array<string, mixed> */
    private function paymentInstruction(DanaSandboxPaymentMethod $paymentMethod, ?string $paymentCode, ?string $qrContent, array $responseBody, bool $isProduction = false): array
    {
        if ($paymentMethod === DanaSandboxPaymentMethod::DanaWallet) {
            return [
                'method' => $paymentMethod->value,
                'label' => $paymentMethod->label(),
                'type' => 'redirect',
                'message' => $isProduction
                    ? 'Lanjutkan pembayaran pada halaman DANA Wallet.'
                    : 'Lanjutkan pembayaran pada halaman DANA Wallet Sandbox.',
            ];
        }

        if ($paymentMethod === DanaSandboxPaymentMethod::Qris) {
            $qr = ($qrContent !== null && $this->isValidEmvcoQris($qrContent))
                ? $qrContent
                : (is_string($paymentCode) && $this->isValidEmvcoQris($paymentCode) ? trim($paymentCode) : null);
            if ($qr !== null) {
                return [
                    'method' => $paymentMethod->value,
                    'label' => $paymentMethod->label(),
                    'type' => 'qris_mpm',
                    'qr_content' => $qr,
                    'payment_code' => $paymentCode ?? $qr,
                    'message' => 'Pindai QRIS menggunakan aplikasi DANA atau aplikasi pembayaran pendukung QRIS.',
                ];
            }

            return [
                'method' => $paymentMethod->value,
                'label' => $paymentMethod->label(),
                'type' => 'payment_code',
                'payment_code' => $paymentCode,
                'message' => 'Kode pembayaran QRIS Custom Checkout. Bukan QR Content MPM dan tidak dirender sebagai QRIS.',
            ];
        }

        return [
            'method' => $paymentMethod->value,
            'label' => $paymentMethod->label(),
            'type' => 'virtual_account',
            'payment_code' => $paymentCode,
            'message' => 'Gunakan nomor Virtual Account ini sebelum masa berlaku berakhir.',
        ];
    }

    private function resolvePaymentMethod(Payment $payment): ?DanaSandboxPaymentMethod
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];
        $method = $metadata['payment_method'] ?? $metadata['udev_sandbox_payment_method'] ?? null;

        return is_string($method) ? DanaSandboxPaymentMethod::tryFrom($method) : null;
    }

    private function sandboxPaymentMethod(Payment $payment): ?DanaSandboxPaymentMethod
    {
        return $this->resolvePaymentMethod($payment);
    }

    private function partnerReferenceNo(Payment $payment): string
    {
        return $payment->partner_reference_no ?? $payment->public_id;
    }

    private function logRequestDiagnostics(Payment $payment, string $body, string $timestamp, string $signature, string $privateKey): void
    {
        if (! $this->diagnosticsEnabled()) {
            return;
        }

        Log::debug('Diagnostik signature DANA Create Order.', [
            'payment_id' => $payment->getKey(),
            'merchant_id' => $payment->merchant_id,
            'payment_reference' => $payment->public_id,
            'method' => 'POST',
            'relative_path' => self::CREATE_ORDER_PATH,
            'body_bytes' => strlen($body),
            'canonical_body_sha256' => hash('sha256', $this->signatureService->minifyJson($body)),
            'timestamp' => $timestamp,
            'signature_bytes' => strlen($signature),
            'signature_verified_locally' => $this->signatureService->verifyWithSigningPrivateKey(
                'POST',
                self::CREATE_ORDER_PATH,
                $body,
                $timestamp,
                $signature,
                $privateKey,
            ),
        ]);
    }

    private function diagnosticsEnabled(): bool
    {
        return app()->environment(['local', 'testing'])
            || filter_var(config('services.dana.diagnostics', false), FILTER_VALIDATE_BOOL);
    }

    private function returnUrl(Payment $payment, string $configuredReturnUrl): string
    {
        $metadata = $payment->metadata;
        $context = is_array($metadata) ? ($metadata['udev_sandbox_return_context'] ?? null) : null;
        $isSandboxTester = is_array($metadata) && ($metadata['udev_sandbox_tester'] ?? false) === true;

        if ($isSandboxTester) {
            if (! is_string($context) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $context) !== 1) {
                return $configuredReturnUrl;
            }

            if (! filter_var($configuredReturnUrl, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($configuredReturnUrl), 'https://')) {
                throw new \LogicException('PAY_RETURN Sandbox tidak valid.');
            }

            return $configuredReturnUrl.(str_contains($configuredReturnUrl, '?') ? '&' : '?').'context='.rawurlencode($context);
        }

        $isUdevBilling = is_array($metadata) && (! empty($metadata['udev_invoice_id']) || ! empty($metadata['udev_payment_attempt_id']));
        if ($isUdevBilling) {
            $reference = (string) $payment->public_id;
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $reference) === 1
                && filter_var($configuredReturnUrl, FILTER_VALIDATE_URL)
                && str_starts_with(strtolower($configuredReturnUrl), 'https://')
                && ! str_contains($configuredReturnUrl, 'reference=')
            ) {
                $separator = str_contains($configuredReturnUrl, '?') ? '&' : '?';

                return $configuredReturnUrl.$separator.'reference='.rawurlencode($reference);
            }
        }

        return $configuredReturnUrl;
    }

    private function logResponseDiagnostics(Payment $payment, Response $response, mixed $responseBody): void
    {
        if (! $this->diagnosticsEnabled()) {
            return;
        }

        $rawMessage = is_array($responseBody)
            ? ($responseBody['responseMessage'] ?? $responseBody['response_message'] ?? null)
            : null;
        $responseMessage = is_string($rawMessage) && $rawMessage !== ''
            ? mb_substr($rawMessage, 0, 200)
            : null;

        Log::debug('Diagnostik respons DANA Create Order.', [
            'payment_id' => $payment->getKey(),
            'merchant_id' => $payment->merchant_id,
            'http_status' => $response->status(),
            'response_code' => is_array($responseBody) ? ($responseBody['responseCode'] ?? null) : null,
            'response_message' => $responseMessage,
            'request_id' => $response->header('X-Request-ID') ?? $response->header('Request-Id'),
        ]);
    }

    private function isValidEmvcoQris(?string $payload): bool
    {
        if (! is_string($payload) || strlen(trim($payload)) < 14 || ! str_starts_with(trim($payload), '000201')) {
            return false;
        }

        $clean = trim($payload);
        $len = strlen($clean);
        $offset = 0;
        $tlvs = [];

        while ($offset < $len) {
            if ($offset + 4 > $len) {
                return false;
            }
            $tag = substr($clean, $offset, 2);
            $lengthStr = substr($clean, $offset + 2, 2);
            if (! ctype_digit($tag) || ! ctype_digit($lengthStr)) {
                return false;
            }
            $valLen = (int) $lengthStr;
            if ($offset + 4 + $valLen > $len) {
                return false;
            }
            $val = substr($clean, $offset + 4, $valLen);
            $tlvs[] = ['tag' => $tag, 'length' => $valLen, 'value' => $val];
            $offset += 4 + $valLen;
        }

        if ($offset !== $len) {
            return false;
        }

        $last = end($tlvs);
        if ($last === false || $last['tag'] !== '63' || $last['length'] !== 4 || ! ctype_xdigit($last['value'])) {
            return false;
        }

        $dataToCrc = substr($clean, 0, -4);
        $expectedCrc = $this->crc16Ccitt($dataToCrc);

        return strcasecmp($expectedCrc, $last['value']) === 0;
    }

    private function crc16Ccitt(string $str): string
    {
        $crc = 0xFFFF;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= (ord($str[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
