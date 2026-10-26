<?php

namespace App\Services;

use App\Enums\DanaSandboxPaymentMethod;
use App\Models\DanaSandboxTestRun;
use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class DanaSandboxTesterService
{
    private const string ProtectedMerchantCode = 'larissama';

    public function __construct(
        private DanaKeyMaterialNormalizer $keyMaterialNormalizer,
        private PaymentService $paymentService,
        private PaymentGatewayInterface $paymentGateway,
        private DanaSandboxQueryPaymentService $queryPaymentService,
    ) {}

    /** @return array<string, mixed> */
    public function validateConfiguration(Merchant $merchant, string $serviceId): array
    {
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        $success = $validation['format_valid'] === true;

        $this->recordRun(
            $merchant,
            'configuration_validated',
            $success ? 'succeeded' : 'failed',
            $success ? 'Format konfigurasi Sandbox valid. Autentikasi DANA belum diuji.' : 'Konfigurasi Sandbox belum siap atau format key tidak valid.',
            ['format_valid' => $success],
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            ...$validation,
            'authentication' => [
                'status' => 'not_attempted',
                'message' => 'Autentikasi DANA hanya dapat dikonfirmasi dari respons Create Order Sandbox yang valid.',
            ],
        ];
    }

    /**
     * @param  array{amount_minor: int, payment_method: string, idempotency_key: string, return_context: ?string}  $attributes
     * @return array<string, mixed>
     */
    public function createTransaction(Merchant $merchant, array $attributes, string $serviceId): array
    {
        $paymentMethod = DanaSandboxPaymentMethod::from($attributes['payment_method']);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true) {
            $this->recordRun(
                $merchant,
                'transaction_created',
                'failed',
                'Transaksi Sandbox tidak dibuat karena konfigurasi belum siap.',
                ['format_valid' => false],
            );

            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk membuat transaksi uji.');
        }

        $credentials = is_array($config?->encrypted_credentials) ? $config->encrypted_credentials : [];
        if ($paymentMethod->requiresExternalStore() && ! $this->configured($credentials['external_store_id'] ?? null)) {
            $this->recordRun(
                $merchant,
                'transaction_created',
                'failed',
                'QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.',
                ['payment_method' => $paymentMethod->value, 'external_store_configured' => false],
            );

            throw new UnprocessableEntityHttpException('QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.');
        }

        $merchantOrderId = $this->testerOrderId($attributes['idempotency_key']);
        $payment = Payment::query()
            ->forMerchant($merchant)
            ->where('idempotency_key', $attributes['idempotency_key'])
            ->first();

        if ($payment !== null && ! $this->matchesExistingTransaction($payment, $attributes)) {
            throw new UnprocessableEntityHttpException('Idempotency-Key sudah digunakan untuk transaksi Sandbox yang berbeda.');
        }

        $returnContext = $payment === null
            ? $attributes['return_context']
            : $this->returnContext($payment);

        $metadata = [
            'udev_sandbox_tester' => true,
            'udev_sandbox_payment_method' => $paymentMethod->value,
        ];
        if ($returnContext !== null) {
            $metadata['udev_sandbox_return_context'] = $returnContext;
        }

        try {
            $payment = $this->paymentService->createHostedCheckout($merchant, [
                'merchant_order_id' => $merchantOrderId,
                'amount_minor' => $attributes['amount_minor'],
                'currency' => 'IDR',
                'metadata' => $metadata,
                'idempotency_key' => $attributes['idempotency_key'],
            ], $this->paymentGateway);
        } catch (\LogicException|\RuntimeException $exception) {
            $payment = Payment::query()
                ->forMerchant($merchant)
                ->where('merchant_order_id', $merchantOrderId)
                ->first();
            $this->recordRun(
                $merchant,
                'transaction_created',
                'failed',
                'Checkout Sandbox belum dapat dibuat. Periksa konfigurasi dan coba kembali.',
                ['payment_created' => $payment !== null],
                $payment,
            );

            throw $exception;
        }

        $checkoutUrl = $this->safeCheckoutUrl($payment->checkout_url);
        $this->recordRun(
            $merchant,
            'transaction_created',
            'succeeded',
            'Checkout Sandbox berhasil dibuat. Status pembayaran masih menunggu verifikasi.',
            ['checkout_available' => $checkoutUrl !== null],
            $payment,
        );

        $result = [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'authentication' => [
                'status' => 'confirmed_by_checkout_response',
                'message' => 'Create Order Sandbox diterima dengan respons checkout yang valid.',
            ],
            'transaction' => $this->paymentData($payment, $checkoutUrl),
        ];
        if (($returnContext = $this->returnContext($payment)) !== null) {
            $result['return_context'] = $returnContext;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function transactionStatus(Merchant $merchant, string $paymentPublicId, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $payment = Payment::query()
            ->forMerchant($merchant)
            ->where('public_id', $paymentPublicId)
            ->whereJsonContains('metadata->udev_sandbox_tester', true)
            ->first();

        if ($payment === null) {
            $this->paymentNotFound($paymentPublicId);
        }

        $this->recordRun(
            $merchant,
            'transaction_status_checked',
            'succeeded',
            'Status transaksi Sandbox dibaca dari status pembayaran terverifikasi yang tersimpan.',
            ['payment_status' => $payment->status],
            $payment,
        );

        $result = [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'transaction' => $this->paymentData($payment, $this->safeCheckoutUrl($payment->checkout_url)),
        ];
        if (($returnContext = $this->returnContext($payment)) !== null) {
            $result['return_context'] = $returnContext;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function queryDanaPaymentStatus(Merchant $merchant, string $paymentPublicId, string $serviceId): array
    {
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        if ($config === null) {
            throw new \LogicException('Konfigurasi DANA Sandbox belum siap.');
        }
        $payment = Payment::query()
            ->forMerchant($merchant)
            ->where('public_id', $paymentPublicId)
            ->where('environment', 'sandbox')
            ->where('provider', 'dana')
            ->whereJsonContains('metadata->udev_sandbox_tester', true)
            ->first();
        if ($payment === null) {
            $this->paymentNotFound($paymentPublicId);
        }

        try {
            $dana = $this->queryPaymentService->query($payment, $config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->recordRun($merchant, 'dana_payment_status_queried', 'failed', 'Status DANA Sandbox belum dapat diperiksa.', [], $payment);

            throw $exception;
        }

        $this->recordRun(
            $merchant,
            'dana_payment_status_queried',
            'succeeded',
            'Status DANA Sandbox diperiksa tanpa mengubah status pembayaran lokal.',
            collect($dana)->only(['http_status', 'response_code', 'latest_transaction_status', 'request_id'])->all(),
            $payment,
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'payment_id' => $payment->public_id,
            'local_status' => $payment->status,
            'dana_status' => $dana,
        ];
    }

    /** @return LengthAwarePaginator<int, DanaSandboxTestRun> */
    public function history(Merchant $merchant, string $serviceId, int $perPage): LengthAwarePaginator
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);

        return DanaSandboxTestRun::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('environment', 'sandbox')
            ->with('payment:id,public_id,status')
            ->latest('id')
            ->paginate($perPage);
    }

    private function sandboxConfiguration(Merchant $merchant, string $serviceId): ?MerchantPaymentConfig
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);

        return MerchantPaymentConfig::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('provider', 'dana')
            ->where('environment', 'sandbox')
            ->first();
    }

    /** @return array<string, mixed> */
    private function configurationValidation(?MerchantPaymentConfig $config): array
    {
        $credentials = is_array($config?->encrypted_credentials) ? $config->encrypted_credentials : [];
        $requiredFields = [
            'merchant_id' => $this->configured($credentials['merchant_id'] ?? null),
            'partner_id' => $this->configured($config?->partner_id),
            'origin' => $this->isHttpsUrl($credentials['origin'] ?? null),
            'channel_id' => $this->configured($credentials['channel_id'] ?? null),
            'notification_url' => $this->isHttpsUrl($credentials['notification_url'] ?? null),
            'pay_return_url' => $this->isHttpsUrl($config?->callback_url),
            'mcc' => is_string($credentials['mcc'] ?? null) && preg_match('/\A\d{4}\z/', $credentials['mcc']) === 1,
        ];
        $privateKey = $this->keyValidation($credentials['private_key'] ?? null, 'private');
        $publicKey = $this->keyValidation($credentials['dana_webhook_public_key'] ?? null, 'public');

        return [
            'configuration_exists' => $config !== null,
            'enabled' => $config?->enabled === true,
            'required_fields' => $requiredFields,
            'private_key' => $privateKey,
            'dana_webhook_public_key' => $publicKey,
            'format_valid' => $config?->enabled === true
                && ! in_array(false, $requiredFields, true)
                && $privateKey['format_valid']
                && $publicKey['format_valid'],
        ];
    }

    /** @return array{configured: bool, format_valid: bool} */
    private function keyValidation(mixed $key, string $kind): array
    {
        if (! $this->configured($key)) {
            return ['configured' => false, 'format_valid' => false];
        }

        try {
            if ($kind === 'private') {
                $this->keyMaterialNormalizer->normalizePrivateKey($key);
            } else {
                $this->keyMaterialNormalizer->normalizePublicKey($key);
            }
        } catch (DanaKeyMaterialException) {
            return ['configured' => true, 'format_valid' => false];
        }

        return ['configured' => true, 'format_valid' => true];
    }

    private function assertTesterCanAccessMerchant(Merchant $merchant, string $serviceId): void
    {
        if (! $merchant->internalProvisioningRequests()->where('service_id', $serviceId)->exists()) {
            $this->merchantNotFound($merchant);
        }

        if ($merchant->code === self::ProtectedMerchantCode) {
            throw new AccessDeniedHttpException('Merchant ini tidak dapat diakses melalui layanan internal.');
        }
    }

    private function testerOrderId(string $idempotencyKey): string
    {
        return 'UDEV-SANDBOX-'.Str::upper(substr(hash('sha256', $idempotencyKey), 0, 40));
    }

    /** @return array<string, mixed> */
    private function paymentData(Payment $payment, ?string $checkoutUrl): array
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];
        $instruction = $metadata['udev_sandbox_payment_instruction'] ?? null;

        return [
            'id' => $payment->public_id,
            'merchant_order_id' => $payment->merchant_order_id,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'checkout_url' => $checkoutUrl,
            'checkout_available' => $checkoutUrl !== null,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'payment_instruction' => is_array($instruction) ? $instruction : null,
            'created_at' => $payment->created_at?->toIso8601String(),
            'updated_at' => $payment->updated_at?->toIso8601String(),
        ];
    }

    private function safeCheckoutUrl(?string $url): ?string
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && str_starts_with(strtolower($url), 'https://')
            ? $url
            : null;
    }

    /** @param array{amount_minor: int, payment_method: string, idempotency_key: string, return_context: ?string} $attributes */
    private function matchesExistingTransaction(Payment $payment, array $attributes): bool
    {
        return $payment->amount_minor === $attributes['amount_minor']
            && $payment->currency === 'IDR'
            && is_array($payment->metadata)
            && ($payment->metadata['udev_sandbox_tester'] ?? false) === true
            && ($payment->metadata['udev_sandbox_payment_method'] ?? null) === $attributes['payment_method'];
    }

    private function returnContext(Payment $payment): ?string
    {
        $context = $payment->metadata['udev_sandbox_return_context'] ?? null;
        if (! is_string($context) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $context) !== 1) {
            return null;
        }

        return $context;
    }

    private function configured(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isHttpsUrl(mixed $value): bool
    {
        return $this->configured($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && str_starts_with(strtolower($value), 'https://');
    }

    /** @param array<string, bool|int|string|null> $safeDetails */
    private function recordRun(Merchant $merchant, string $operation, string $status, string $message, array $safeDetails, ?Payment $payment = null): void
    {
        DanaSandboxTestRun::query()->create([
            'merchant_id' => $merchant->getKey(),
            'payment_id' => $payment?->getKey(),
            'operation' => $operation,
            'environment' => 'sandbox',
            'status' => $status,
            'message' => $message,
            'safe_details' => $safeDetails,
        ]);
    }

    private function merchantNotFound(Merchant $merchant): never
    {
        throw (new ModelNotFoundException)->setModel(Merchant::class, [$merchant->getKey()]);
    }

    private function paymentNotFound(string $paymentPublicId): never
    {
        throw (new ModelNotFoundException)->setModel(Payment::class, [$paymentPublicId]);
    }
}
