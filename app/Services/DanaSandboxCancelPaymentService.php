<?php

namespace App\Services;

use App\Enums\DanaSandboxPaymentMethod;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class DanaSandboxCancelPaymentService
{
    private const string CANCEL_PAYMENT_PATH = '/payment-gateway/v1.0/debit/cancel.htm';

    /**
     * Kode mock resmi DANA Sandbox untuk pengujian Cancel Order (SNAP Service Code 57).
     * DANA Sandbox mengevaluasi kode ini bila dikirimkan pada outbound originalPartnerReferenceNo.
     *
     * @var array<int, string>
     */
    public const array SANDBOX_MOCK_CODES = [
        '2025700', // Request In Progress
        '4035705', // Do Not Honor (Transaction Not Permitted)
        '4045708', // Invalid Merchant (Merchant Status Abnormal)
        '4045701', // Transaction Not Found
        '4035700', // Exceed Cancel Window Time (Transaction Expired)
        '4035715', // Not Allowed by Agreement (Transaction Not Permitted)
        '4035714', // Insufficience of Merchant Balance (Insufficient Funds)
        '4005702', // Missing Mandatory Field (Omit X-TIMESTAMP)
        '4015700', // Unauthorized (Invalid Signature)
    ];

    public function __construct(
        private DanaCredentialResolver $credentialResolver,
        private DanaSnapSignatureService $signatureService,
    ) {}

    /**
     * Membatalkan transaksi nyata di DANA Sandbox (Happy Path / 2005700).
     * Mengirimkan identifier transaksi asli ($payment->partner_reference_no) sebagai originalPartnerReferenceNo.
     *
     * @return array<string, mixed>
     */
    public function cancel(Payment $payment, MerchantPaymentConfig $config, ?string $reason = null, ?string $partnerReferenceNo = null): array
    {
        // Kompatibilitas mundur: jika pemanggil menyuplai kode mock pada argumen ke-4
        if ($partnerReferenceNo !== null && in_array($partnerReferenceNo, self::SANDBOX_MOCK_CODES, true)) {
            return $this->simulateMockCancel($payment, $config, $partnerReferenceNo, $reason);
        }

        $genuineReferenceNo = $payment->partner_reference_no ?? $payment->public_id;

        return $this->executeCancelRequest(
            payment: $payment,
            config: $config,
            outboundOriginalPartnerReferenceNo: $genuineReferenceNo,
            reason: $reason ?? 'Cancelled by merchant in Sandbox',
            isMock: false,
            mockCode: null,
        );
    }

    /**
     * Menjalankan simulasi skenario mock UAT DANA Sandbox (2025700, 4035705, 4045708, 4045701, 4005702).
     * Mengirimkan mockCode sebagai outbound originalPartnerReferenceNo ke DANA Sandbox (kecuali 4005702 yang
     * menggunakan identifier transaksi asli dan meniadakan header X-TIMESTAMP),
     * tanpa mengubah identifier transaksi asli yang tersimpan di database lokal.
     *
     * @return array<string, mixed>
     */
    public function simulateMockCancel(Payment $payment, MerchantPaymentConfig $config, string $mockCode, ?string $reason = null): array
    {
        if (! in_array($mockCode, self::SANDBOX_MOCK_CODES, true)) {
            throw new \InvalidArgumentException(
                "Kode mock DANA Sandbox tidak didukung: {$mockCode}. Kode yang diizinkan: " . implode(', ', self::SANDBOX_MOCK_CODES)
            );
        }

        if ($mockCode === '4005702') {
            return $this->cancelMissingTimestamp($payment, $config, $reason);
        }

        if ($mockCode === '4015700') {
            return $this->cancelInvalidSignature($payment, $config, $reason);
        }

        return $this->executeCancelRequest(
            payment: $payment,
            config: $config,
            outboundOriginalPartnerReferenceNo: $mockCode,
            reason: $reason ?? "UAT Mock Cancel ({$mockCode})",
            isMock: true,
            mockCode: $mockCode,
        );
    }

    /**
     * Menjalankan UAT DANA Sandbox skenario Missing Mandatory Field (4005702).
     * Mengirimkan request Cancel Order dengan identifier transaksi asli ($payment->partner_reference_no)
     * namun dengan sengaja MENIADAKAN header X-TIMESTAMP dari outbound HTTP request.
     *
     * @return array<string, mixed>
     */
    public function cancelMissingTimestamp(Payment $payment, MerchantPaymentConfig $config, ?string $reason = null): array
    {
        $genuineReferenceNo = $payment->partner_reference_no ?? $payment->public_id;

        return $this->executeCancelRequest(
            payment: $payment,
            config: $config,
            outboundOriginalPartnerReferenceNo: $genuineReferenceNo,
            reason: $reason ?? 'UAT Mock Cancel Missing Mandatory Field (4005702)',
            isMock: true,
            mockCode: '4005702',
            omitTimestamp: true,
        );
    }

    /**
     * Menjalankan UAT DANA Sandbox skenario Invalid Signature (4015700).
     * Mengirimkan request Cancel Order dengan identifier transaksi asli ($payment->partner_reference_no)
     * dan seluruh header SNAP valid, namun dengan sengaja MENGGANTI header X-SIGNATURE menjadi literal "invalid_signature".
     *
     * @return array<string, mixed>
     */
    public function cancelInvalidSignature(Payment $payment, MerchantPaymentConfig $config, ?string $reason = null): array
    {
        $genuineReferenceNo = $payment->partner_reference_no ?? $payment->public_id;

        return $this->executeCancelRequest(
            payment: $payment,
            config: $config,
            outboundOriginalPartnerReferenceNo: $genuineReferenceNo,
            reason: $reason ?? 'UAT Mock Cancel Invalid Signature (4015700)',
            isMock: true,
            mockCode: '4015700',
            omitTimestamp: false,
            invalidSignature: true,
        );
    }

    /**
     * Eksekusi HTTP call Debit Cancel ke DANA Sandbox sesuai spesifikasi Bank Indonesia SNAP.
     *
     * @return array<string, mixed>
     */
    private function executeCancelRequest(
        Payment $payment,
        MerchantPaymentConfig $config,
        string $outboundOriginalPartnerReferenceNo,
        string $reason,
        bool $isMock,
        ?string $mockCode,
        bool $omitTimestamp = false,
        bool $invalidSignature = false,
    ): array {
        if ($payment->environment !== 'sandbox' || $payment->provider !== 'dana') {
            throw new \LogicException('Pembatalan transaksi hanya tersedia untuk pembayaran DANA Sandbox.');
        }

        $credentials = $this->credentialResolver->resolve($config);
        $method = is_array($payment->metadata) ? ($payment->metadata['udev_sandbox_payment_method'] ?? null) : null;
        if ($method === DanaSandboxPaymentMethod::Qris->value && $credentials->externalStoreId === null) {
            throw new \LogicException('QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.');
        }

        $timestamp = now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP');
        $payload = [
            'originalPartnerReferenceNo' => $outboundOriginalPartnerReferenceNo,
            'merchantId' => $credentials->merchantId,
            'reason' => $reason,
        ];

        // Sesuai spesifikasi SNAP Debit Cancel (Service Code 57), partnerReferenceNo tidak dikirimkan.

        if ($credentials->subMerchantId !== null) {
            $payload['subMerchantId'] = $credentials->subMerchantId;
        }

        if ($method === DanaSandboxPaymentMethod::Qris->value && $credentials->externalStoreId !== null) {
            $payload['externalStoreId'] = $credentials->externalStoreId;
        }

        // originalReferenceNo hanya disertakan pada transaksi riil jika sudah tersimpan
        if (! $isMock && is_array($payment->metadata) && ! empty($payment->metadata['dana_reference_no'])) {
            $payload['originalReferenceNo'] = (string) $payment->metadata['dana_reference_no'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = $invalidSignature
            ? 'invalid_signature'
            : $this->signatureService->signTransactional('POST', self::CANCEL_PAYMENT_PATH, $body, $timestamp, $credentials->privateKey);
        $externalId = 'cancel-'.Str::ulid();

        $headers = [
            'X-SIGNATURE' => $signature,
            'ORIGIN' => $credentials->origin,
            'X-PARTNER-ID' => $credentials->partnerId,
            'X-EXTERNAL-ID' => $externalId,
            'CHANNEL-ID' => $credentials->channelId,
        ];

        if (! $omitTimestamp) {
            $headers['X-TIMESTAMP'] = $timestamp;
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.dana.timeout', 8))
                ->connectTimeout((int) config('services.dana.connect_timeout', 3))
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('services.dana.base_url'), '/').self::CANCEL_PAYMENT_PATH);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Layanan pembatalan DANA Sandbox sementara tidak tersedia.', previous: $exception);
        }

        $responseBody = $response->json();
        $bodyData = is_array($responseBody) ? $responseBody : [];
        $genuineOriginalPartnerReferenceNo = $payment->partner_reference_no ?? $payment->public_id;

        return [
            'http_status' => $response->status(),
            'response_code' => $this->stringOrNull($bodyData['responseCode'] ?? null),
            'response_message' => $this->stringOrNull($bodyData['responseMessage'] ?? null),
            'cancel_time' => $this->stringOrNull($bodyData['cancelTime'] ?? null),
            'is_mock' => $isMock,
            'mock_code' => $mockCode,
            'partner_reference_no' => $mockCode,
            'original_partner_reference_no' => $genuineOriginalPartnerReferenceNo,
            'outbound_original_partner_reference_no' => $outboundOriginalPartnerReferenceNo,
            'x_timestamp_sent' => ! $omitTimestamp,
            'signature_override' => $invalidSignature ? 'invalid_signature' : null,
            'x_signature_invalid' => $invalidSignature,
            'request_id' => $this->stringOrNull($bodyData['requestID'] ?? null)
                ?? $this->stringOrNull($response->header('X-Request-ID'))
                ?? $this->stringOrNull($response->header('Request-Id')),
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
            'raw_response' => $bodyData,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 150) : null;
    }
}
