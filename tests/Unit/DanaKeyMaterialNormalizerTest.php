<?php

namespace Tests\Unit;

use App\Services\DanaKeyMaterialException;
use App\Services\DanaKeyMaterialNormalizer;
use App\Services\DanaSnapSignatureService;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\DanaSandboxKeyMaterialFixture;

class DanaKeyMaterialNormalizerTest extends TestCase
{
    public function test_it_accepts_valid_rsa_2048_pem_key_material(): void
    {
        $normalizer = new DanaKeyMaterialNormalizer;

        $privateKey = $normalizer->normalizePrivateKey(DanaSandboxKeyMaterialFixture::PrivateKey);
        $publicKey = $normalizer->normalizePublicKey(DanaSandboxKeyMaterialFixture::PublicKey);

        $this->assertSame(DanaSandboxKeyMaterialFixture::PrivateKey, $privateKey);
        $this->assertSame(DanaSandboxKeyMaterialFixture::PublicKey, $publicKey);
    }

    public function test_it_accepts_supported_base64_der_and_restores_the_verified_pem_wrapper(): void
    {
        $normalizer = new DanaKeyMaterialNormalizer;

        $privateKey = $normalizer->normalizePrivateKey($this->pemToBase64Der(DanaSandboxKeyMaterialFixture::PrivateKey));
        $publicKey = $normalizer->normalizePublicKey($this->pemToBase64Der(DanaSandboxKeyMaterialFixture::PublicKey));

        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $privateKey);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $publicKey);
    }

    public function test_it_rejects_invalid_base64_wrong_key_kind_and_unsupported_key_types(): void
    {
        $normalizer = new DanaKeyMaterialNormalizer;

        $this->expectException(DanaKeyMaterialException::class);
        $normalizer->normalizePrivateKey('base64-yang-rusak!');
    }

    public function test_it_rejects_empty_key_material_without_echoing_it(): void
    {
        $normalizer = new DanaKeyMaterialNormalizer;

        $this->expectException(DanaKeyMaterialException::class);
        $normalizer->normalizePrivateKey('   ');
    }

    public function test_it_rejects_a_pem_certificate_instead_of_a_supported_public_key(): void
    {
        $normalizer = new DanaKeyMaterialNormalizer;

        $this->expectException(DanaKeyMaterialException::class);
        $normalizer->normalizePublicKey("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----");
    }

    public function test_it_rejects_a_public_key_as_a_private_key_and_private_key_as_a_public_key(): void
    {
        $normalizer = new DanaKeyMaterialNormalizer;

        try {
            $normalizer->normalizePrivateKey(DanaSandboxKeyMaterialFixture::PublicKey);
            $this->fail('Public key tidak boleh diperlakukan sebagai private key.');
        } catch (DanaKeyMaterialException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(DanaKeyMaterialException::class);
        $normalizer->normalizePublicKey(DanaSandboxKeyMaterialFixture::PrivateKey);
    }

    public function test_normalized_private_key_remains_usable_for_existing_rsa_sha256_signature_flow(): void
    {
        $normalizer = new DanaKeyMaterialNormalizer;
        $signatureService = new DanaSnapSignatureService;
        $privateKey = $normalizer->normalizePrivateKey(DanaSandboxKeyMaterialFixture::PrivateKey);
        $publicKey = $normalizer->normalizePublicKey(DanaSandboxKeyMaterialFixture::PublicKey);
        $body = '{"partnerReferenceNo":"sandbox-test"}';
        $timestamp = '2026-10-17T10:00:00+07:00';
        $path = '/payment-gateway/v1.0/debit/payment-host-to-host.htm';

        $signature = $signatureService->signTransactional('POST', $path, $body, $timestamp, $privateKey);

        $this->assertTrue($signatureService->verifyTransactional('POST', $path, $body, $timestamp, $signature, $publicKey));
    }

    private function pemToBase64Der(string $pem): string
    {
        $base64 = preg_replace('/-----BEGIN [A-Z ]+-----|-----END [A-Z ]+-----|\s+/', '', $pem);
        self::assertIsString($base64);

        return $base64;
    }
}
