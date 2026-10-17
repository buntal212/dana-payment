<?php

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
]);

if ($key === false) {
    while ($error = openssl_error_string()) {
        echo $error, PHP_EOL;
    }
    exit(1);
}

echo 'RSA KEY BERHASIL DIBUAT', PHP_EOL;
