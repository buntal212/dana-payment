<?php

namespace Tests\Unit;

use App\Services\DanaSnapSignatureService;
use PHPUnit\Framework\TestCase;

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
}
