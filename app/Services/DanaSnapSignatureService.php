<?php

namespace App\Services;

use RuntimeException;

class DanaSnapSignatureService
{
    /**
     * Public Key resmi DANA Sandbox (dashboard.dana.id / SNAP standard).
     */
    public const DANA_SANDBOX_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\n"
        ."MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAnaKVGRbin4Wh4KN35OPh\n"
        ."ytJBjYTz7QZKSZjmHfiHxFmulfT87rta+IvGJ0rCBgg+1EtKk1hX8G5gPGJs1htJ\n"
        ."5jHa3/jCk9l+luzjnuT9UVlwJahvzmFw+IoDoM7hIPjsLtnIe04SgYo0tZBpEmkQ\n"
        ."vUGhmHPqYnUGSSMIpDLJDvbyr8gtwluja1SbRphgDCoYVXq+uUJ5HzPS049aaxTS\n"
        ."nfXh/qXuDoB9EzCrgppLDS2ubmk21+dr7WaO/3RFjnwx5ouv6w+iC1XOJKar3CTk\n"
        ."X6JV1OSST1C9sbPGzMHZ8AGB51BM0mok7davD/5irUk+f0C25OgzkwtxAt80dkDo\n"
        ."/QIDAQAB\n"
        ."-----END PUBLIC KEY-----";

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

    /**
     * @param list<string> $candidatePaths
     */
    public function verifyTransactional(string $method, string $relativePath, string $jsonBody, string $timestamp, string $signature, string $publicKey, array $candidatePaths = [], ?string $fallbackPublicKey = null): bool
    {
        return $this->transactionalVerificationDiagnostics($method, $relativePath, $jsonBody, $timestamp, $signature, $publicKey, $candidatePaths, $fallbackPublicKey)['signature_valid'];
    }

    /**
     * @param list<string> $candidatePaths
     * @return array{
     *     signature_valid: bool,
     *     signature_base64_valid: bool,
     *     signature_decoded_bytes: int|null,
     *     public_key_valid: bool,
     *     public_key_bits: int|null,
     *     public_key_sha256: string|null,
     *     public_key_is_rsa: bool,
     *     string_to_sign_sha256: string,
     *     openssl_verify_result: int|null,
     *     openssl_verification_completed: bool,
     *     matched_body_mode?: string|null,
     *     matched_path?: string|null,
     *     matched_key_source?: string|null
     * }
     */
    public function transactionalVerificationDiagnostics(string $method, string $relativePath, string $jsonBody, string $timestamp, string $signature, string $publicKey, array $candidatePaths = [], ?string $fallbackPublicKey = null): array
    {
        $decodedSignature = base64_decode($signature, true);
        $primaryKey = @openssl_pkey_get_public($publicKey);
        $primaryDetails = $primaryKey === false ? null : openssl_pkey_get_details($primaryKey);
        $defaultStringToSign = $this->transactionalStringToSign($method, $relativePath, $jsonBody, $timestamp);

        if ($decodedSignature === false || ($primaryKey === false && $fallbackPublicKey === null)) {
            return [
                'signature_valid' => false,
                'signature_base64_valid' => $decodedSignature !== false,
                'signature_decoded_bytes' => $decodedSignature === false ? null : strlen($decodedSignature),
                'public_key_valid' => $primaryKey !== false,
                'public_key_bits' => is_array($primaryDetails) && is_int($primaryDetails['bits'] ?? null) ? $primaryDetails['bits'] : null,
                'public_key_sha256' => is_array($primaryDetails) && is_string($primaryDetails['key'] ?? null) ? $this->publicKeyFingerprint($primaryDetails['key']) : null,
                'public_key_is_rsa' => is_array($primaryDetails) && ($primaryDetails['type'] ?? null) === OPENSSL_KEYTYPE_RSA,
                'string_to_sign_sha256' => hash('sha256', $defaultStringToSign),
                'openssl_verify_result' => null,
                'openssl_verification_completed' => false,
                'matched_body_mode' => null,
                'matched_path' => null,
                'matched_key_source' => null,
            ];
        }

        $paths = ! empty($candidatePaths)
            ? array_values(array_unique(array_filter([$relativePath, ...$candidatePaths])))
            : [$relativePath];

        // Sesuai SNAP dan spesifikasi resmi DANA (Requirement 5):
        // Utamakan digest dari raw request body yang ditransmisikan, dengan fallback minified JSON
        $bodyCandidates = [
            'raw' => hash('sha256', $jsonBody),
        ];

        try {
            $minified = $this->minifyJson($jsonBody);
            $minifiedHash = hash('sha256', $minified);
            if ($minifiedHash !== $bodyCandidates['raw']) {
                $bodyCandidates['minified'] = $minifiedHash;
            }
        } catch (\Throwable) {
            // Payload tidak dapat di-minify, gunakan raw body
        }

        $keysToTry = [];
        if ($primaryKey !== false) {
            $keysToTry['configured'] = [
                'key' => $primaryKey,
                'details' => $primaryDetails,
            ];
        }

        if ($fallbackPublicKey !== null && $fallbackPublicKey !== $publicKey) {
            $fallbackKey = @openssl_pkey_get_public($fallbackPublicKey);
            if ($fallbackKey !== false) {
                $keysToTry['official_sandbox_fallback'] = [
                    'key' => $fallbackKey,
                    'details' => openssl_pkey_get_details($fallbackKey),
                ];
            }
        }

        $matched = null;
        $lastVerifyResult = null;
        $lastStringToSign = $defaultStringToSign;

        foreach ($keysToTry as $source => $keyInfo) {
            $keyResource = $keyInfo['key'];
            foreach ($paths as $path) {
                foreach ($bodyCandidates as $mode => $digest) {
                    $candidateStringToSign = strtoupper($method).':'.$path.':'.$digest.':'.$timestamp;
                    $verifyResult = @openssl_verify($candidateStringToSign, $decodedSignature, $keyResource, OPENSSL_ALGO_SHA256);

                    if ($verifyResult === 1) {
                        $matched = [
                            'key_source' => $source,
                            'path' => $path,
                            'body_mode' => $mode,
                            'string_to_sign' => $candidateStringToSign,
                            'details' => $keyInfo['details'],
                        ];
                        break 3;
                    }

                    if ($source === 'configured' && $path === $relativePath) {
                        $lastVerifyResult = $verifyResult;
                        $lastStringToSign = $candidateStringToSign;
                    }
                }
            }
        }

        if ($matched !== null) {
            $activeDetails = $matched['details'];

            return [
                'signature_valid' => true,
                'signature_base64_valid' => true,
                'signature_decoded_bytes' => strlen($decodedSignature),
                'public_key_valid' => true,
                'public_key_bits' => is_array($activeDetails) && is_int($activeDetails['bits'] ?? null) ? $activeDetails['bits'] : null,
                'public_key_sha256' => is_array($activeDetails) && is_string($activeDetails['key'] ?? null) ? $this->publicKeyFingerprint($activeDetails['key']) : null,
                'public_key_is_rsa' => is_array($activeDetails) && ($activeDetails['type'] ?? null) === OPENSSL_KEYTYPE_RSA,
                'string_to_sign_sha256' => hash('sha256', $matched['string_to_sign']),
                'openssl_verify_result' => 1,
                'openssl_verification_completed' => true,
                'matched_body_mode' => $matched['body_mode'],
                'matched_path' => $matched['path'],
                'matched_key_source' => $matched['key_source'],
            ];
        }

        $activeDetails = $primaryDetails ?? ($keysToTry['official_sandbox_fallback']['details'] ?? null);

        return [
            'signature_valid' => false,
            'signature_base64_valid' => true,
            'signature_decoded_bytes' => strlen($decodedSignature),
            'public_key_valid' => $primaryKey !== false,
            'public_key_bits' => is_array($activeDetails) && is_int($activeDetails['bits'] ?? null) ? $activeDetails['bits'] : null,
            'public_key_sha256' => is_array($activeDetails) && is_string($activeDetails['key'] ?? null) ? $this->publicKeyFingerprint($activeDetails['key']) : null,
            'public_key_is_rsa' => is_array($activeDetails) && ($activeDetails['type'] ?? null) === OPENSSL_KEYTYPE_RSA,
            'string_to_sign_sha256' => hash('sha256', $lastStringToSign),
            'openssl_verify_result' => is_int($lastVerifyResult) ? $lastVerifyResult : 0,
            'openssl_verification_completed' => $lastVerifyResult !== -1,
            'matched_body_mode' => null,
            'matched_path' => null,
            'matched_key_source' => null,
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
