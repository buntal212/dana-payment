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
        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        $key = @openssl_pkey_get_public($publicKey);
        if ($key === false) {
            return false;
        }

        return @openssl_verify($this->transactionalStringToSign($method, $relativePath, $jsonBody, $timestamp), $decodedSignature, $key, OPENSSL_ALGO_SHA256) === 1;
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
