<?php

namespace App\Services;

use RuntimeException;

class DanaSnapSignatureService
{
    public function signTransactional(string $method, string $relativePath, string $minifiedJsonBody, string $timestamp, string $privateKey): string
    {
        $key = @openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new RuntimeException('Private key DANA tidak dapat digunakan.');
        }

        $signed = @openssl_sign($this->stringToSign($method, $relativePath, $minifiedJsonBody, $timestamp), $signature, $key, OPENSSL_ALGO_SHA256);

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

        $key = @openssl_pkey_get_public($publicKey);
        if ($key === false) {
            return false;
        }

        return @openssl_verify($this->stringToSign($method, $relativePath, $minifiedJsonBody, $timestamp), $decodedSignature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function minifyJson(string $json): string
    {
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $minified = '';
        $inString = false;
        $escaped = false;
        $length = strlen($json);

        for ($index = 0; $index < $length; $index++) {
            $character = $json[$index];

            if ($inString) {
                $minified .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
                $minified .= $character;

                continue;
            }

            if (! in_array($character, [' ', "\n", "\r", "\t"], true)) {
                $minified .= $character;
            }
        }

        return $minified;
    }

    private function stringToSign(string $method, string $relativePath, string $minifiedJsonBody, string $timestamp): string
    {
        return strtoupper($method).':'.$relativePath.':'.hash('sha256', $minifiedJsonBody).':'.$timestamp;
    }
}
