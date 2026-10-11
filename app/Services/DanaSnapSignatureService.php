<?php

namespace App\Services;

use RuntimeException;

class DanaSnapSignatureService
{
    public function signTransactional(string $method, string $relativePath, string $minifiedJsonBody, string $timestamp, string $privateKey): string
    {
        $signed = openssl_sign($this->stringToSign($method, $relativePath, $minifiedJsonBody, $timestamp), $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if ($signed !== true) {
            throw new RuntimeException('Tidak dapat membuat signature DANA.');
        }

        return base64_encode($signature);
    }

    public function verifyTransactional(string $method, string $relativePath, string $minifiedJsonBody, string $timestamp, string $signature, string $publicKey): bool
    {
        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        return openssl_verify($this->stringToSign($method, $relativePath, $minifiedJsonBody, $timestamp), $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    public function minifyJson(string $json): string
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    private function stringToSign(string $method, string $relativePath, string $minifiedJsonBody, string $timestamp): string
    {
        return strtoupper($method).':'.$relativePath.':'.hash('sha256', $minifiedJsonBody).':'.$timestamp;
    }
}
