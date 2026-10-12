<?php

namespace App\Services;

use RuntimeException;

class DanaSnapSignatureService
{
    public function signTransactional(string $method, string $relativePath, string $jsonBody, string $timestamp, string $privateKey): string
    {
        $key = @openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new RuntimeException('Private key DANA tidak dapat digunakan.');
        }

        $signed = @openssl_sign($this->transactionalStringToSign($method, $relativePath, $jsonBody, $timestamp), $signature, $key, OPENSSL_ALGO_SHA256);

        if ($signed !== true) {
            throw new RuntimeException('Tidak dapat membuat signature DANA.');
        }

        return base64_encode($signature);
    }

    public function verifyTransactional(string $method, string $relativePath, string $jsonBody, string $timestamp, string $signature, string $publicKey): bool
    {
        return $this->transactionalVerificationDiagnostics($method, $relativePath, $jsonBody, $timestamp, $signature, $publicKey)['signature_valid'];
    }

    /**
     * @return array{signature_valid: bool, signature_base64_valid: bool, signature_decoded_bytes: int|null, public_key_valid: bool, public_key_bits: int|null, public_key_sha256: string|null, public_key_is_rsa: bool, string_to_sign_sha256: string, openssl_verify_result: int|null, openssl_verification_completed: bool}
     */
    public function transactionalVerificationDiagnostics(string $method, string $relativePath, string $jsonBody, string $timestamp, string $signature, string $publicKey): array
    {
        $decodedSignature = base64_decode($signature, true);
        $key = @openssl_pkey_get_public($publicKey);
        $details = $key === false ? null : openssl_pkey_get_details($key);
        $stringToSign = $this->transactionalStringToSign($method, $relativePath, $jsonBody, $timestamp);

        if ($decodedSignature === false || $key === false) {
            return [
                'signature_valid' => false,
                'signature_base64_valid' => $decodedSignature !== false,
                'signature_decoded_bytes' => $decodedSignature === false ? null : strlen($decodedSignature),
                'public_key_valid' => $key !== false,
                'public_key_bits' => is_array($details) && is_int($details['bits'] ?? null) ? $details['bits'] : null,
                'public_key_sha256' => is_array($details) && is_string($details['key'] ?? null) ? $this->publicKeyFingerprint($details['key']) : null,
                'public_key_is_rsa' => is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA,
                'string_to_sign_sha256' => hash('sha256', $stringToSign),
                'openssl_verify_result' => null,
                'openssl_verification_completed' => false,
            ];
        }

        $verificationResult = @openssl_verify($stringToSign, $decodedSignature, $key, OPENSSL_ALGO_SHA256);

        return [
            'signature_valid' => $verificationResult === 1,
            'signature_base64_valid' => true,
            'signature_decoded_bytes' => strlen($decodedSignature),
            'public_key_valid' => true,
            'public_key_bits' => is_array($details) && is_int($details['bits'] ?? null) ? $details['bits'] : null,
            'public_key_sha256' => is_array($details) && is_string($details['key'] ?? null) ? $this->publicKeyFingerprint($details['key']) : null,
            'public_key_is_rsa' => is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA,
            'string_to_sign_sha256' => hash('sha256', $stringToSign),
            'openssl_verify_result' => is_int($verificationResult) ? $verificationResult : null,
            'openssl_verification_completed' => $verificationResult !== -1,
        ];
    }

    private function publicKeyFingerprint(string $publicKey): ?string
    {
        $base64 = preg_replace('/-----(?:BEGIN|END) PUBLIC KEY-----|\s+/', '', $publicKey);
        if (! is_string($base64)) {
            return null;
        }

        $der = base64_decode($base64, true);

        return $der === false ? null : hash('sha256', $der);
    }

    /**
     * Memverifikasi signature yang baru dibuat tanpa mengekspos material kunci.
     * Digunakan hanya oleh diagnostik server-side Create Order.
     */
    public function verifyWithSigningPrivateKey(string $method, string $relativePath, string $jsonBody, string $timestamp, string $signature, string $privateKey): bool
    {
        $key = @openssl_pkey_get_private($privateKey);
        if ($key === false) {
            return false;
        }

        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ! is_string($details['key'] ?? null)) {
            return false;
        }

        return $this->verifyTransactional($method, $relativePath, $jsonBody, $timestamp, $signature, $details['key']);
    }

    public function minifyJson(string $json): string
    {
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Formula SNAP: HTTP_METHOD:RELATIVE_PATH:SHA256(Minify(HTTP_BODY)):X-TIMESTAMP.
     */
    public function transactionalStringToSign(string $method, string $relativePath, string $jsonBody, string $timestamp): string
    {
        return strtoupper($method).':'.$relativePath.':'.hash('sha256', $this->minifyJson($jsonBody)).':'.$timestamp;
    }
}
