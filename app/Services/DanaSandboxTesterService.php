<?php

namespace App\Services;

use App\Enums\DanaSandboxPaymentMethod;
use App\Models\DanaSandboxTestRun;
use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class DanaSandboxTesterService
{
    private const string ProtectedMerchantCode = 'larissama';

    public function __construct(
        private DanaKeyMaterialNormalizer $keyMaterialNormalizer,
        private PaymentService $paymentService,
        private PaymentGatewayInterface $paymentGateway,
        private DanaSandboxQueryPaymentService $queryPaymentService,
        private DanaSandboxCreateOrderUatService $createOrderUatService,
        private DanaSandboxIpgCashierUatService $ipgCashierUatService,
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

    /** @return array<string, mixed> */
    public function testCreateOrderMissingTimestamp(Merchant $merchant, string $serviceId): array
    {
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT Create Order.');
        }

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => $this->createOrderUatService->missingTimestamp($config),
        ];
    }

    /** @return array<string, mixed> */
    public function testCreateOrderInvalidAmountFormat(Merchant $merchant, string $serviceId): array
    {
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT Create Order.');
        }

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => $this->createOrderUatService->invalidAmountFormat($config),
        ];
    }

    /** @return array<string, mixed> */
    public function testCreateOrderInconsistentRequest(Merchant $merchant, string $serviceId): array
    {
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT Create Order.');
        }

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => $this->createOrderUatService->inconsistentRequest($config),
        ];
    }

    /** @return array<string, mixed> */
    public function testIpgCreateOrderSuccess(Merchant $merchant, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT IPG Cashier Pay.');
        }

        try {
            $uat = $this->ipgCashierUatService->createOrderSuccess($config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->safeRecordRun(
                $merchant,
                'ipg_create_order_success_tested',
                'failed',
                'UAT IPG Cashier Pay Create Order belum dapat dieksekusi: '.$exception->getMessage(),
                ['endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host', 'passed' => false]
            );

            throw $exception;
        }

        $passed = ($uat['passed'] ?? false) === true;
        $received = is_array($uat['received'] ?? null) ? $uat['received'] : [];

        $this->safeRecordRun(
            $merchant,
            'ipg_create_order_success_tested',
            $passed ? 'succeeded' : 'failed',
            $passed
                ? 'UAT IPG Cashier Pay Create Order (2005400) berhasil diverifikasi.'
                : 'UAT IPG Cashier Pay Create Order (2005400) tidak mengembalikan kode yang diharapkan.',
            [
                'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                'http_status' => $received['http_status'] ?? null,
                'response_code' => $received['response_code'] ?? null,
                'response_message' => $received['response_message'] ?? null,
                'reference_no' => $received['reference_no'] ?? null,
                'request_id' => $received['request_id'] ?? null,
                'passed' => $passed,
            ]
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => $uat,
        ];
    }

    /** @return array<string, mixed> */
    public function testIpgCreateOrderGeneralError(Merchant $merchant, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT IPG Cashier Pay.');
        }

        try {
            $uat = $this->ipgCashierUatService->generalError($config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->safeRecordRun(
                $merchant,
                'ipg_create_order_general_error_tested',
                'failed',
                'UAT IPG Cashier Pay General Error belum dapat dieksekusi: '.$exception->getMessage(),
                ['endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host', 'amount_value' => '505400.00', 'passed' => false, 'transaction_status' => 'FAILED']
            );

            throw $exception;
        }

        $passed = ($uat['passed'] ?? false) === true;
        $received = is_array($uat['received'] ?? null) ? $uat['received'] : [];

        $this->safeRecordRun(
            $merchant,
            'ipg_create_order_general_error_tested',
            'failed',
            $passed
                ? 'UAT IPG Cashier Pay General Error (5005400) berhasil memverifikasi transaksi gagal (FAILED).'
                : 'UAT IPG Cashier Pay General Error (5005400) tidak mengembalikan kode yang diharapkan.',
            [
                'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                'amount_value' => '505400.00',
                'http_status' => $received['http_status'] ?? null,
                'response_code' => $received['response_code'] ?? null,
                'response_message' => $received['response_message'] ?? null,
                'request_id' => $received['request_id'] ?? null,
                'passed' => $passed,
                'transaction_status' => 'FAILED',
            ]
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => $uat,
        ];
    }

    /** @return array<string, mixed> */
    public function testIpgCreateOrderTransactionNotPermitted(Merchant $merchant, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT IPG Cashier Pay.');
        }

        try {
            $uat = $this->ipgCashierUatService->transactionNotPermitted($config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->safeRecordRun(
                $merchant,
                'ipg_create_order_tx_not_permitted_tested',
                'failed',
                'UAT IPG Cashier Pay Transaction Not Permitted belum dapat dieksekusi: '.$exception->getMessage(),
                ['endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host', 'amount_value' => '435415.00', 'passed' => false, 'transaction_status' => 'FAILED']
            );

            throw $exception;
        }

        $passed = ($uat['passed'] ?? false) === true;
        $received = is_array($uat['received'] ?? null) ? $uat['received'] : [];

        $this->safeRecordRun(
            $merchant,
            'ipg_create_order_tx_not_permitted_tested',
            'failed',
            $passed
                ? 'UAT IPG Cashier Pay Transaction Not Permitted (4035415) berhasil memverifikasi transaksi gagal (FAILED).'
                : 'UAT IPG Cashier Pay Transaction Not Permitted (4035415) tidak mengembalikan kode yang diharapkan.',
            [
                'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                'amount_value' => '435415.00',
                'http_status' => $received['http_status'] ?? null,
                'response_code' => $received['response_code'] ?? null,
                'response_message' => $received['response_message'] ?? null,
                'request_id' => $received['request_id'] ?? null,
                'passed' => $passed,
                'transaction_status' => 'FAILED',
            ]
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => $uat,
        ];
    }

    /** @return array<string, mixed> */
    public function testIpgCreateOrderInvalidMerchant(Merchant $merchant, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT IPG Cashier Pay.');
        }

        try {
            $uat = $this->ipgCashierUatService->invalidMerchant($config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->safeRecordRun(
                $merchant,
                'ipg_create_order_invalid_merchant_tested',
                'failed',
                'UAT IPG Cashier Pay Invalid Merchant belum dapat dieksekusi: '.$exception->getMessage(),
                ['endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host', 'amount_value' => '445408.00', 'passed' => false, 'transaction_status' => 'FAILED']
            );

            throw $exception;
        }

        $passed = ($uat['passed'] ?? false) === true;
        $received = is_array($uat['received'] ?? null) ? $uat['received'] : [];

        $this->safeRecordRun(
            $merchant,
            'ipg_create_order_invalid_merchant_tested',
            'failed',
            $passed
                ? 'UAT IPG Cashier Pay Invalid Merchant (4045408) berhasil memverifikasi transaksi gagal (FAILED).'
                : 'UAT IPG Cashier Pay Invalid Merchant (4045408) tidak mengembalikan kode yang diharapkan.',
            [
                'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                'amount_value' => '445408.00',
                'http_status' => $received['http_status'] ?? null,
                'response_code' => $received['response_code'] ?? null,
                'response_message' => $received['response_message'] ?? null,
                'request_id' => $received['request_id'] ?? null,
                'passed' => $passed,
                'transaction_status' => 'FAILED',
            ]
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => $uat,
        ];
    }

    /** @return array<string, mixed> */
    public function testIpgCreateOrderInconsistentRequest(Merchant $merchant, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT IPG Cashier Pay.');
        }

        try {
            $uat = $this->ipgCashierUatService->inconsistentRequest($config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->safeRecordRun(
                $merchant,
                'ipg_create_order_inconsistent_tested',
                'failed',
                'UAT IPG Cashier Pay Inconsistent Request belum dapat dieksekusi: '.$exception->getMessage(),
                ['endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host', 'passed' => false, 'transaction_status' => 'FAILED']
            );

            throw $exception;
        }

        $passed = ($uat['passed'] ?? false) === true;
        $received = is_array($uat['received'] ?? null) ? $uat['received'] : [];
        $firstReq = is_array($uat['first_request'] ?? null) ? $uat['first_request'] : [];
        $secondReq = is_array($uat['second_request'] ?? null) ? $uat['second_request'] : [];
        $req = is_array($uat['request'] ?? null) ? $uat['request'] : [];

        $this->safeRecordRun(
            $merchant,
            'ipg_create_order_inconsistent_tested',
            'failed',
            $passed
                ? 'UAT IPG Cashier Pay Inconsistent Request (4045418) berhasil memverifikasi transaksi gagal (FAILED).'
                : 'UAT IPG Cashier Pay Inconsistent Request (4045418) tidak mengembalikan kode yang diharapkan.',
            [
                'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                'partner_reference_no' => $req['partner_reference_no'] ?? null,
                'first_amount' => $req['first_amount'] ?? '10000.00',
                'second_amount' => $req['second_amount'] ?? '20000.00',
                'http_status' => $received['http_status'] ?? null,
                'response_code' => $received['response_code'] ?? null,
                'response_message' => $received['response_message'] ?? null,
                'request_id' => $received['request_id'] ?? null,
                'first_response_code' => $firstReq['response_code'] ?? null,
                'second_response_code' => $secondReq['response_code'] ?? null,
                'passed' => $passed,
                'transaction_status' => 'FAILED',
            ]
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => $uat,
        ];
    }

    /** @return array<string, mixed> */
    public function testIpgCreateOrderMissingTimestamp(Merchant $merchant, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT IPG Cashier Pay.');
        }

        try {
            $uat = $this->ipgCashierUatService->missingTimestamp($config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->safeRecordRun(
                $merchant,
                'ipg_create_order_missing_ts_tested',
                'failed',
                'UAT IPG Cashier Pay tanpa X-TIMESTAMP belum dapat dieksekusi: '.$exception->getMessage(),
                ['endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host', 'passed' => false]
            );

            throw $exception;
        }

        $passed = ($uat['passed'] ?? false) === true;
        $received = is_array($uat['received'] ?? null) ? $uat['received'] : [];

        $this->safeRecordRun(
            $merchant,
            'ipg_create_order_missing_ts_tested',
            $passed ? 'succeeded' : 'failed',
            $passed
                ? 'UAT IPG Cashier Pay tanpa X-TIMESTAMP (4005402) berhasil diverifikasi.'
                : 'UAT IPG Cashier Pay tanpa X-TIMESTAMP (4005402) tidak mengembalikan kode yang diharapkan.',
            [
                'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                'http_status' => $received['http_status'] ?? null,
                'response_code' => $received['response_code'] ?? null,
                'response_message' => $received['response_message'] ?? null,
                'request_id' => $received['request_id'] ?? null,
                'passed' => $passed,
            ]
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => $uat,
        ];
    }

    /** @return array<string, mixed> */
    public function testDebitStatusTransactionNotFound(Merchant $merchant, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT Debit Status.');
        }

        try {
            $result = $this->queryPaymentService->queryFictitiousReference('12345678', $config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->recordRun($merchant, 'debit_status_transaction_not_found_tested', 'failed', 'Pemeriksaan UAT Debit Status belum dapat dieksekusi.', ['original_partner_reference_no' => '12345678']);

            throw $exception;
        }

        $responseCode = $result['response_code'] ?? null;
        $passed = $responseCode === '4045501';

        $this->recordRun(
            $merchant,
            'debit_status_transaction_not_found_tested',
            $passed ? 'succeeded' : 'failed',
            $passed ? 'UAT Debit Status Transaction Not Found (4045501) berhasil diverifikasi.' : 'UAT Debit Status Transaction Not Found (4045501) tidak mengembalikan kode yang diharapkan.',
            [
                'original_partner_reference_no' => '12345678',
                'service_code' => '54',
                'http_status' => $result['http_status'],
                'response_code' => $responseCode,
                'response_message' => $result['response_message'] ?? null,
                'request_id' => $result['request_id'] ?? null,
            ],
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => [
                'scenario' => 'debit_status_transaction_not_found',
                'passed' => $passed,
                'expected' => [
                    'response_code' => '4045501',
                    'response_message' => 'Transaction Not Found',
                ],
                'received' => [
                    'http_status' => $result['http_status'],
                    'response_code' => $responseCode,
                    'response_message' => $result['response_message'] ?? 'Tidak tersedia',
                    'request_id' => $result['request_id'],
                ],
                'request' => [
                    'original_partner_reference_no' => '12345678',
                    'service_code' => '54',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function testDebitStatusMissingTimestamp(Merchant $merchant, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT Debit Status.');
        }

        try {
            $result = $this->queryPaymentService->queryMissingTimestamp($config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->recordRun($merchant, 'debit_status_missing_timestamp_tested', 'failed', 'Pemeriksaan UAT Debit Status belum dapat dieksekusi.', ['x_timestamp_sent' => false]);

            throw $exception;
        }

        $responseCode = $result['response_code'] ?? null;
        $passed = $responseCode === '4005502';

        $this->recordRun(
            $merchant,
            'debit_status_missing_timestamp_tested',
            $passed ? 'succeeded' : 'failed',
            $passed ? 'UAT Debit Status Missing Timestamp (4005502) berhasil diverifikasi.' : 'UAT Debit Status Missing Timestamp (4005502) tidak mengembalikan kode yang diharapkan.',
            [
                'service_code' => '54',
                'http_status' => $result['http_status'],
                'response_code' => $responseCode,
                'response_message' => $result['response_message'] ?? null,
                'request_id' => $result['request_id'] ?? null,
                'x_timestamp_sent' => false,
            ],
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => [
                'scenario' => 'debit_status_missing_x_timestamp',
                'passed' => $passed,
                'expected' => [
                    'response_code' => '4005502',
                    'response_message' => 'Invalid Mandatory Field X-TIMESTAMP',
                ],
                'received' => [
                    'http_status' => $result['http_status'],
                    'response_code' => $responseCode,
                    'response_message' => $result['response_message'] ?? 'Tidak tersedia',
                    'request_id' => $result['request_id'],
                ],
                'request' => [
                    'service_code' => '54',
                    'x_timestamp_sent' => false,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function testDebitStatusInternalServerError(Merchant $merchant, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT Debit Status.');
        }

        try {
            $result = $this->queryPaymentService->queryInternalServerError($config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->recordRun($merchant, 'debit_status_internal_server_error_tested', 'failed', 'Pemeriksaan UAT Debit Status belum dapat dieksekusi.', ['service_code' => 'AZ']);

            throw $exception;
        }

        $responseCode = $result['response_code'] ?? null;
        $passed = $responseCode === '5005501';

        $this->recordRun(
            $merchant,
            'debit_status_internal_server_error_tested',
            $passed ? 'succeeded' : 'failed',
            $passed ? 'UAT Debit Status Internal Server Error (5005501) berhasil diverifikasi.' : 'UAT Debit Status Internal Server Error (5005501) tidak mengembalikan kode yang diharapkan.',
            [
                'service_code' => 'AZ',
                'http_status' => $result['http_status'],
                'response_code' => $responseCode,
                'response_message' => $result['response_message'] ?? null,
                'request_id' => $result['request_id'] ?? null,
            ],
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => [
                'scenario' => 'debit_status_internal_server_error',
                'passed' => $passed,
                'expected' => [
                    'response_code' => '5005501',
                    'response_message' => 'Internal Server Error',
                ],
                'received' => [
                    'http_status' => $result['http_status'],
                    'response_code' => $responseCode,
                    'response_message' => $result['response_message'] ?? 'Tidak tersedia',
                    'request_id' => $result['request_id'],
                ],
                'request' => [
                    'service_code' => 'AZ',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function testDebitStatusInvalidSignature(Merchant $merchant, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);
        $config = $this->sandboxConfiguration($merchant, $serviceId);
        $validation = $this->configurationValidation($config);
        if ($validation['format_valid'] !== true || $config === null) {
            throw new UnprocessableEntityHttpException('Konfigurasi DANA Sandbox belum siap untuk menjalankan UAT Debit Status.');
        }

        try {
            $result = $this->queryPaymentService->queryInvalidSignature($config);
        } catch (\LogicException|\RuntimeException $exception) {
            $this->recordRun($merchant, 'debit_status_invalid_signature_tested', 'failed', 'Pemeriksaan UAT Debit Status belum dapat dieksekusi.', ['signature_override' => 'invalid_signature']);

            throw $exception;
        }

        $responseCode = $result['response_code'] ?? null;
        $passed = $responseCode === '4015500';

        $this->recordRun(
            $merchant,
            'debit_status_invalid_signature_tested',
            $passed ? 'succeeded' : 'failed',
            $passed ? 'UAT Debit Status Invalid Signature (4015500) berhasil diverifikasi.' : 'UAT Debit Status Invalid Signature (4015500) tidak mengembalikan kode yang diharapkan.',
            [
                'service_code' => '54',
                'http_status' => $result['http_status'],
                'response_code' => $responseCode,
                'response_message' => $result['response_message'] ?? null,
                'request_id' => $result['request_id'] ?? null,
                'signature_override' => 'invalid_signature',
            ],
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => [
                'scenario' => 'debit_status_invalid_signature',
                'passed' => $passed,
                'expected' => [
                    'response_code' => '4015500',
                    'response_message' => 'Unauthorized',
                ],
                'received' => [
                    'http_status' => $result['http_status'],
                    'response_code' => $responseCode,
                    'response_message' => $result['response_message'] ?? 'Tidak tersedia',
                    'request_id' => $result['request_id'],
                ],
                'request' => [
                    'service_code' => '54',
                    'signature_override' => 'invalid_signature',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function armWebhookSimulated500(Merchant $merchant, ?string $paymentPublicId, string $serviceId): array
    {
        $this->assertTesterCanAccessMerchant($merchant, $serviceId);

        $query = Payment::query()
            ->forMerchant($merchant)
            ->where('environment', 'sandbox')
            ->where('status', 'pending')
            ->whereJsonContains('metadata->udev_sandbox_tester', true);

        if (is_string($paymentPublicId) && $paymentPublicId !== '') {
            $payment = $query->where('public_id', $paymentPublicId)->first();
        } else {
            $payment = $query->latest('id')->first();
        }

        if ($payment === null) {
            throw new UnprocessableEntityHttpException('Tidak ditemukan transaksi Sandbox berstatus pending untuk simulasi Webhook 500. Silakan buat transaksi Sandbox terlebih dahulu.');
        }

        $ttlMinutes = 10;
        $cacheKey = $this->simulateWebhook500CacheKey($merchant->getKey(), $payment->getKey());
        Cache::put($cacheKey, [
            'merchant_id' => $merchant->getKey(),
            'payment_id' => $payment->getKey(),
            'payment_public_id' => $payment->public_id,
            'environment' => 'sandbox',
            'armed_at' => now()->toIso8601String(),
        ], now()->addMinutes($ttlMinutes));

        $this->recordRun(
            $merchant,
            'uat_webhook_500_armed',
            'succeeded',
            'Simulasi Finish Notify HTTP 500 (5005601) berhasil diaktifkan selama 10 menit untuk satu kali penggunaan.',
            ['payment_public_id' => $payment->public_id, 'expires_at' => now()->addMinutes($ttlMinutes)->toISOString()],
            $payment,
        );

        return [
            'merchant_code' => $merchant->code,
            'environment' => 'sandbox',
            'uat' => [
                'scenario' => 'finish_notify_simulated_500',
                'armed' => true,
                'payment_id' => $payment->public_id,
                'partner_reference_no' => $payment->partner_reference_no ?? $payment->public_id,
                'expected' => [
                    'http_status' => 500,
                    'response_code' => '5005601',
                    'response_message' => 'Internal Server Error',
                ],
                'single_use' => true,
                'expires_in_minutes' => $ttlMinutes,
                'expires_at' => now('Asia/Jakarta')->addMinutes($ttlMinutes)->format('Y-m-d\\TH:i:sP'),
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
        } catch (ConflictHttpException $exception) {
            $payment = Payment::query()
                ->forMerchant($merchant)
                ->where('merchant_order_id', $merchantOrderId)
                ->first();
            $this->recordRun(
                $merchant,
                'transaction_created',
                'failed',
                'Create Order Sandbox berkonflik dengan transaksi yang sudah ada.',
                ['conflict_type' => $this->conflictType($exception)],
                $payment,
            );

            throw $exception;
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

    private function conflictType(ConflictHttpException $exception): string
    {
        return match ($exception->getMessage()) {
            'Idempotency-Key sudah digunakan untuk payload yang berbeda.' => 'idempotency_payload_mismatch',
            'merchant_order_id sudah digunakan.' => 'merchant_order_id',
            default => 'storage',
        };
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
    private function safeRecordRun(Merchant $merchant, string $operation, string $status, string $message, array $safeDetails, ?Payment $payment = null): void
    {
        try {
            $this->recordRun($merchant, $operation, $status, $message, $safeDetails, $payment);
        } catch (\Throwable $exception) {
            Log::warning('Gagal mencatat riwayat pengujian Sandbox DANA.', [
                'merchant_id' => $merchant->getKey(),
                'operation' => $operation,
                'error' => $exception->getMessage(),
            ]);
        }
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

    public static function simulateWebhook500CacheKey(int|string $merchantId, int|string $paymentId): string
    {
        return "dana:uat:simulate_notify_500:merchant:{$merchantId}:payment:{$paymentId}";
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
