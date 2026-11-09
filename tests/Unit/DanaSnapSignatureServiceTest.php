<?php

namespace Tests\Unit;

use App\Services\DanaSnapSignatureService;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\DanaSandboxKeyMaterialFixture;

class DanaSnapSignatureServiceTest extends TestCase
{
    private const TEST_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEArtXD6xu8tsNK6pTY6o0K
As4LDImOiKL5xYxDVFwPGVvXNHowqWgoq5t961RC1xysE4rScf4sJFHJ+0n/qg0c
zp97T0FGX6fvbMJZW491ROfpdoopWvksF2lW6PsbnwuMmebzqupkY5HR68NBdJyA
1Rh4UwraH66XQfTGDTNBVmIHwqHqPA1acPRB44ktWkDmlnNe/ASsXKWGMct393w0
5uyHgctzo3RFy2y0Es0hwANS3qs83IPym2EYZgoegY92uodtOBBePb/WPj2KwVdT
PuSAsRlJI8lf1PyV3GvYbO2tezG+4V82xGkEvy/VJpwrXfJ/rzt61Zxt8UJRrvI5
TwIDAQAB
-----END PUBLIC KEY-----
PEM;

    private const TEST_SIGNATURE = 'UZVtTQn3qIdDEyECd/y9t94axNJ8eT4m6L6rt6OStE4ci0JcI6FFIVxB1Nx7NipFeuFtlaApFluTApqH5XWa5FOtDgui8vNKRXsuXvb0Gj7Q3kuwJ6rKSMHZOqobR8yOe5oBtySvgtgujZnLfoPfavdZRPuiZJdghUzLwm6BX/1iwL5AguOFuFptuZZnxYdtFe+ESK7bmG1aabv3LezSGGdf3EcKojNeMS6gCfplkDqLz1TLNh25lr+K5KF1Vqh8RtNYTUtAWGZhsiMXSYTOAfKlbxZ3CLFIrrFxISPLLz/lgjZOUYnl3Fpsd62piQPuB1H9utWpzUCVtMXi1xNN3w==';

    public function test_it_canonicalizes_json_according_to_the_snap_minify_vector(): void
    {
        $json = "{\n  \"message\" : \"space stays here\",\n  \"path\" : \"a\\/b\",\n  \"label\" : \"\\u00e9\"\n}";

        $minified = (new DanaSnapSignatureService)->minifyJson($json);

        $this->assertSame('{"message":"space stays here","path":"a/b","label":"'."\xC3\xA9".'"}', $minified);
    }

    public function test_it_builds_the_documented_transactional_string_to_sign(): void
    {
        $service = new DanaSnapSignatureService;
        $body = "{\n  \"message\" : \"space stays here\",\n  \"path\" : \"a\\/b\",\n  \"label\" : \"\\u00e9\"\n}";

        $this->assertSame(
            'POST:/payment-gateway/v1.0/debit/payment-host-to-host.htm:d67c9afb68066509bc3ea73f763124a9939c97a4a0ca5d0a1232e0708eb91f35:2026-10-12T10:11:12+07:00',
            $service->transactionalStringToSign('POST', '/payment-gateway/v1.0/debit/payment-host-to-host.htm', $body, '2026-10-12T10:11:12+07:00'),
        );
    }

    public function test_it_uses_the_actual_merchant_callback_path_in_the_transactional_string_to_sign(): void
    {
        $service = new DanaSnapSignatureService;
        $body = '{"originalPartnerReferenceNo":"payment-001"}';
        $timestamp = '2026-10-12T10:11:12+07:00';

        $this->assertSame(
            'POST:/api/v1/webhooks/dana:05087c0debfac657cbb4b8a6353b3acb8e22494b9710c030c041a117e16f6464:2026-10-12T10:11:12+07:00',
            $service->transactionalStringToSign('POST', '/api/v1/webhooks/dana', $body, $timestamp),
        );
    }

    public function test_signature_test_vector_verifies_and_fails_when_signed_input_changes(): void
    {
        $service = new DanaSnapSignatureService;
        $body = "{\n  \"partnerReferenceNo\": \"payment-001\",\n  \"url\": \"https:\\/\\/merchant.example\\/payment\\/finish\"\n}";
        $timestamp = '2026-10-12T10:11:12+07:00';
        $path = '/payment-gateway/v1.0/debit/payment-host-to-host.htm';

        $this->assertTrue($service->verifyTransactional('POST', $path, $body, $timestamp, self::TEST_SIGNATURE, self::TEST_PUBLIC_KEY));
        $this->assertFalse($service->verifyTransactional('POST', $path, '{"partnerReferenceNo":"payment-002","url":"https://merchant.example/payment/finish"}', $timestamp, self::TEST_SIGNATURE, self::TEST_PUBLIC_KEY));
        $this->assertFalse($service->verifyTransactional('POST', '/v1.0/debit/notify', $body, $timestamp, self::TEST_SIGNATURE, self::TEST_PUBLIC_KEY));
        $this->assertFalse($service->verifyTransactional('POST', $path, $body, '2026-10-12T10:11:13+07:00', self::TEST_SIGNATURE, self::TEST_PUBLIC_KEY));
    }

    public function test_verification_diagnostics_distinguish_base64_public_key_and_signature_failures(): void
    {
        $service = new DanaSnapSignatureService;
        $body = '{"partnerReferenceNo":"payment-001","url":"https://merchant.example/payment/finish"}';
        $timestamp = '2026-10-12T10:11:12+07:00';
        $path = '/payment-gateway/v1.0/debit/payment-host-to-host.htm';

        $valid = $service->transactionalVerificationDiagnostics('POST', $path, $body, $timestamp, self::TEST_SIGNATURE, self::TEST_PUBLIC_KEY);
        $this->assertTrue($valid['signature_valid']);
        $this->assertTrue($valid['signature_base64_valid']);
        $this->assertSame(256, $valid['signature_decoded_bytes']);
        $this->assertTrue($valid['public_key_valid']);
        $this->assertSame(2048, $valid['public_key_bits']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $valid['public_key_sha256']);
        $this->assertTrue($valid['public_key_is_rsa']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $valid['string_to_sign_sha256']);
        $this->assertSame(1, $valid['openssl_verify_result']);
        $this->assertTrue($valid['openssl_verification_completed']);

        $invalidBase64 = $service->transactionalVerificationDiagnostics('POST', $path, $body, $timestamp, 'not-base64!', self::TEST_PUBLIC_KEY);
        $this->assertFalse($invalidBase64['signature_valid']);
        $this->assertFalse($invalidBase64['signature_base64_valid']);
        $this->assertNull($invalidBase64['signature_decoded_bytes']);
        $this->assertTrue($invalidBase64['public_key_valid']);
        $this->assertNull($invalidBase64['openssl_verify_result']);

        $invalidPublicKey = $service->transactionalVerificationDiagnostics('POST', $path, $body, $timestamp, self::TEST_SIGNATURE, 'not-a-pem');
        $this->assertFalse($invalidPublicKey['signature_valid']);
        $this->assertTrue($invalidPublicKey['signature_base64_valid']);
        $this->assertFalse($invalidPublicKey['public_key_valid']);
        $this->assertNull($invalidPublicKey['public_key_bits']);
        $this->assertNull($invalidPublicKey['public_key_sha256']);
        $this->assertNull($invalidPublicKey['openssl_verify_result']);
    }

    public function test_verification_succeeds_with_raw_unminified_body_digest(): void
    {
        $service = new DanaSnapSignatureService;
        $privateKey = DanaSandboxKeyMaterialFixture::PrivateKey;
        $publicKey = DanaSandboxKeyMaterialFixture::PublicKey;

        $rawBody = "{\n  \"merchantId\" : \"216620010005053684810\",\n  \"originalReferenceNo\" : \"20261109001\"\n}";
        $timestamp = '2026-11-09T14:01:07.123+07:00';
        $path = '/api/v1/webhooks/dana';

        // Buktikan bahwa raw body hash berbeda dengan minified hash
        $this->assertNotSame(hash('sha256', $rawBody), hash('sha256', $service->minifyJson($rawBody)));

        // Buat signature resmi DANA menggunakan raw body SHA256 (Requirement 5)
        $rawStringToSign = 'POST:'.$path.':'.hash('sha256', $rawBody).':'.$timestamp;
        $keyResource = openssl_pkey_get_private($privateKey);
        $this->assertNotFalse($keyResource);
        openssl_sign($rawStringToSign, $rawSignatureBytes, $keyResource, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($rawSignatureBytes);

        $diag = $service->transactionalVerificationDiagnostics('POST', $path, $rawBody, $timestamp, $signature, $publicKey);
        $this->assertTrue($diag['signature_valid']);
        $this->assertSame(1, $diag['openssl_verify_result']);
        $this->assertSame('raw', $diag['matched_body_mode']);
        $this->assertSame($path, $diag['matched_path']);
        $this->assertSame('configured', $diag['matched_key_source']);
    }

    public function test_verification_matches_candidate_snap_path(): void
    {
        $service = new DanaSnapSignatureService;
        $privateKey = DanaSandboxKeyMaterialFixture::PrivateKey;
        $publicKey = DanaSandboxKeyMaterialFixture::PublicKey;

        $rawBody = '{"originalPartnerReferenceNo":"PAY-001"}';
        $timestamp = '2026-11-09T14:01:07+07:00';
        $snapPath = '/v1.0/debit/notify';
        $receivedPath = '/api/v1/webhooks/dana';

        // DANA menandatangani menggunakan path standar SNAP (/v1.0/debit/notify)
        $stringToSign = 'POST:'.$snapPath.':'.hash('sha256', $rawBody).':'.$timestamp;
        $keyResource = openssl_pkey_get_private($privateKey);
        $this->assertNotFalse($keyResource);
        openssl_sign($stringToSign, $rawSignatureBytes, $keyResource, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($rawSignatureBytes);

        $diag = $service->transactionalVerificationDiagnostics(
            'POST',
            $receivedPath,
            $rawBody,
            $timestamp,
            $signature,
            $publicKey,
            [$receivedPath, '/v1.0/debit/notify'],
        );

        $this->assertTrue($diag['signature_valid']);
        $this->assertSame('/v1.0/debit/notify', $diag['matched_path']);
    }

    public function test_verification_uses_fallback_official_sandbox_key_when_configured_key_differs(): void
    {
        $service = new DanaSnapSignatureService;

        // Kunci resmi Sandbox DANA (menggunakan fixture)
        $danaPrivateKey = DanaSandboxKeyMaterialFixture::PrivateKey;
        $danaPublicKey = DanaSandboxKeyMaterialFixture::PublicKey;

        // Kunci yang salah dikonfigurasi pada merchant (menggunakan TEST_PUBLIC_KEY yang berbeda)
        $wrongPublicKey = self::TEST_PUBLIC_KEY;

        $rawBody = '{"originalPartnerReferenceNo":"PAY-002"}';
        $timestamp = '2026-11-09T14:01:07+07:00';
        $path = '/api/v1/webhooks/dana';

        // DANA Sandbox menandatangani webhook dengan DANA private key
        $stringToSign = 'POST:'.$path.':'.hash('sha256', $rawBody).':'.$timestamp;
        $danaKeyResource = openssl_pkey_get_private($danaPrivateKey);
        $this->assertNotFalse($danaKeyResource);
        openssl_sign($stringToSign, $rawSignatureBytes, $danaKeyResource, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($rawSignatureBytes);

        // Jika hanya menggunakan kunci merchant yang salah, verifikasi gagal
        $diagWithoutFallback = $service->transactionalVerificationDiagnostics('POST', $path, $rawBody, $timestamp, $signature, $wrongPublicKey);
        $this->assertFalse($diagWithoutFallback['signature_valid']);

        // Dengan fallback official Sandbox key, verifikasi berhasil dan mencatat sumber kuncinya
        $diagWithFallback = $service->transactionalVerificationDiagnostics('POST', $path, $rawBody, $timestamp, $signature, $wrongPublicKey, [], $danaPublicKey);
        $this->assertTrue($diagWithFallback['signature_valid']);
        $this->assertSame('official_sandbox_fallback', $diagWithFallback['matched_key_source']);
    }
}
