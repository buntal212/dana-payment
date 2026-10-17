<?php

namespace App\Services;

class DanaKeyMaterialNormalizer
{
    private const int RequiredRsaBits = 2048;

    public function normalizePrivateKey(string $material): string
    {
        $pem = $this->pemOrDer($material, 'private');
        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new DanaKeyMaterialException('Private key tidak dapat dibaca. Tempelkan key dari Portal DANA dalam format PEM atau Base64 DER yang utuh.');
        }

        $details = $this->assertRsa2048($key, 'Private key');
        $signed = @openssl_sign('dana-key-material-validation', $signature, $key, OPENSSL_ALGO_SHA256);
        if ($signed !== true || ! is_string($signature) || $signature === '' || ! is_string($details['key'] ?? null)) {
            throw new DanaKeyMaterialException('Private key tidak dapat digunakan untuk signature RSA-SHA256. Gunakan private key Sandbox merchant dari Portal DANA.');
        }

        return $pem;
    }

    public function normalizePublicKey(string $material): string
    {
        $pem = $this->pemOrDer($material, 'public');
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new DanaKeyMaterialException('Public key DANA tidak dapat dibaca. Tempelkan public key dari Portal DANA dalam format PEM atau Base64 DER yang utuh.');
        }

        $this->assertRsa2048($key, 'Public key DANA');

        return $pem;
    }

    private function pemOrDer(string $material, string $kind): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($material));
        if ($normalized === '') {
            throw new DanaKeyMaterialException($kind === 'private'
                ? 'Private key wajib diisi untuk konfigurasi baru.'
                : 'Public key DANA wajib diisi untuk konfigurasi baru.');
        }

        if (str_contains($normalized, '-----BEGIN ')) {
            $this->assertSupportedPemLabel($normalized, $kind);

            return $normalized;
        }

        $base64 = preg_replace('/\s+/', '', $normalized);
        if (! is_string($base64) || $base64 === '' || preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $base64) !== 1) {
            throw new DanaKeyMaterialException('Format key tidak dikenali. Tempelkan PEM lengkap atau Base64 DER dari Portal DANA.');
        }

        $der = base64_decode($base64, true);
        if ($der === false || $der === '') {
            throw new DanaKeyMaterialException('Base64 key tidak valid. Salin ulang seluruh key dari Portal DANA.');
        }

        $header = $kind === 'private'
            ? $this->privateDerHeader($der)
            : $this->publicDerHeader($der);

        return $this->toPem($header, $der);
    }

    private function privateDerHeader(string $der): string
    {
        $elements = $this->sequenceElements($der);
        if (count($elements) >= 3 && $elements[0][0] === 0x02 && $elements[1][0] === 0x30 && $elements[2][0] === 0x04) {
            return 'PRIVATE KEY'; // PKCS#8
        }
        if (count($elements) >= 4 && $elements[0][0] === 0x02 && $elements[1][0] === 0x02 && $elements[2][0] === 0x02) {
            return 'RSA PRIVATE KEY'; // PKCS#1
        }

        throw new DanaKeyMaterialException('Base64 yang ditempel bukan struktur private key PKCS#8 atau PKCS#1 yang didukung.');
    }

    private function publicDerHeader(string $der): string
    {
        $elements = $this->sequenceElements($der);
        if (count($elements) === 2 && $elements[0][0] === 0x30 && $elements[1][0] === 0x03) {
            return 'PUBLIC KEY'; // SubjectPublicKeyInfo (SPKI)
        }
        if (count($elements) === 2 && $elements[0][0] === 0x02 && $elements[1][0] === 0x02) {
            return 'RSA PUBLIC KEY'; // PKCS#1
        }

        throw new DanaKeyMaterialException('Base64 yang ditempel bukan struktur public key SPKI atau PKCS#1 yang didukung.');
    }

    /** @return list<array{0: int, 1: string}> */
    private function sequenceElements(string $der): array
    {
        [$tag, $content, $nextOffset] = $this->readDerElement($der, 0);
        if ($tag !== 0x30 || $nextOffset !== strlen($der)) {
            throw new DanaKeyMaterialException('Base64 key bukan DER ASN.1 yang utuh.');
        }

        $elements = [];
        $offset = 0;
        while ($offset < strlen($content)) {
            [$childTag, $childContent, $offset] = $this->readDerElement($content, $offset);
            $elements[] = [$childTag, $childContent];
        }

        return $elements;
    }

    /** @return array{0: int, 1: string, 2: int} */
    private function readDerElement(string $input, int $offset): array
    {
        $length = strlen($input);
        if ($offset + 2 > $length) {
            throw new DanaKeyMaterialException('Base64 key bukan DER ASN.1 yang valid.');
        }

        $tag = ord($input[$offset++]);
        $firstLengthByte = ord($input[$offset++]);
        if (($firstLengthByte & 0x80) === 0) {
            $contentLength = $firstLengthByte;
        } else {
            $lengthBytes = $firstLengthByte & 0x7F;
            if ($lengthBytes === 0 || $lengthBytes > 4 || $offset + $lengthBytes > $length) {
                throw new DanaKeyMaterialException('Base64 key bukan DER ASN.1 yang valid.');
            }
            $contentLength = 0;
            for ($index = 0; $index < $lengthBytes; $index++) {
                $contentLength = ($contentLength << 8) | ord($input[$offset++]);
            }
        }

        if ($contentLength < 0 || $offset + $contentLength > $length) {
            throw new DanaKeyMaterialException('Base64 key bukan DER ASN.1 yang valid.');
        }

        return [$tag, substr($input, $offset, $contentLength), $offset + $contentLength];
    }

    private function toPem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n".rtrim(chunk_split(base64_encode($der), 64, "\n"), "\n")."\n-----END {$label}-----";
    }

    private function assertSupportedPemLabel(string $pem, string $kind): void
    {
        if (preg_match('/\A-----BEGIN ([A-Z ]+)-----\n[A-Za-z0-9+\/=\n]+\n-----END \1-----\z/', $pem, $matches) !== 1) {
            throw new DanaKeyMaterialException('Struktur PEM key tidak lengkap. Salin seluruh key dari Portal DANA tanpa mengubah isinya.');
        }

        $supportedLabels = $kind === 'private'
            ? ['PRIVATE KEY', 'RSA PRIVATE KEY']
            : ['PUBLIC KEY', 'RSA PUBLIC KEY'];

        if (! in_array($matches[1], $supportedLabels, true)) {
            throw new DanaKeyMaterialException($kind === 'private'
                ? 'Format PEM bukan private key PKCS#8 atau PKCS#1 yang didukung.'
                : 'Format PEM bukan public key SPKI atau PKCS#1 yang didukung.');
        }
    }

    /** @return array<string, mixed> */
    private function assertRsa2048(\OpenSSLAsymmetricKey $key, string $label): array
    {
        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new DanaKeyMaterialException("{$label} harus berupa RSA-2048.");
        }
        if (($details['bits'] ?? null) !== self::RequiredRsaBits) {
            throw new DanaKeyMaterialException("{$label} harus berukuran RSA-2048 sesuai skema autentikasi DANA.");
        }

        return $details;
    }
}
