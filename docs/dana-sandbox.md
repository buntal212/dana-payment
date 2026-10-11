# DANA Sandbox Hosted Checkout

Integrasi ini memakai Create Order Hosted Checkout DANA dan menerima Finish Notify pada `POST /api/v1/webhooks/dana`. Tidak ada credential yang disimpan dalam repository atau `.env` contoh.

## Konfigurasi per merchant

Simpan satu baris aktif pada `merchant_payment_configs` dengan `provider=dana` dan `environment=sandbox`. Kolom `partner_id` berisi `X-PARTNER-ID`, sedangkan `callback_url` berisi URL `PAY_RETURN` HTTPS milik merchant.

Simpan JSON berikut melalui jalur administratif tepercaya pada `encrypted_credentials`; cast Eloquent akan mengenkripsinya dengan `APP_KEY`:

```json
{
  "merchant_id": "DANA merchant ID",
  "private_key": "PEM private key merchant",
  "dana_public_key": "PEM public key DANA Sandbox",
  "origin": "domain-aplikasi.example",
  "channel_id": "CHANNEL-ID dari onboarding",
  "notification_url": "https://gateway.example/api/v1/webhooks/dana",
  "mcc": "MCC merchant"
}
```

Nilai `notification_url` harus dapat diakses publik melalui HTTPS dan persis sama dengan endpoint yang didaftarkan di Portal DANA. Jangan memasukkan credential melalui argumen Artisan, source code, log, atau repository.

Gunakan perintah server-side berikut untuk membuat atau memperbarui konfigurasi merchant. Perintah meminta nilai non-rahasia secara interaktif dan membaca isi PEM dari berkas lokal tanpa mencetaknya:

```powershell
php artisan merchant:dana:configure larissama
```

Konfigurasi aplikasi yang tidak rahasia tersedia melalui environment, tanpa mengubah `.env` yang dilacak:

```text
DANA_SANDBOX_BASE_URL=https://api.sandbox.dana.id
DANA_TIMEOUT=8
DANA_CONNECT_TIMEOUT=3
DANA_WEBHOOK_RELATIVE_PATH=/api/v1/webhooks/dana
```

## Alur

`POST /api/v1/payments` membuat payment berstatus `pending`, kemudian meminta Create Order DANA dengan `partnerReferenceNo` berupa `payments.public_id`. Respons hanya disimpan bila `responseCode` adalah `2005400` dan memiliki `referenceNo` serta `webRedirectUrl`. Status tetap `pending` hingga Finish Notify tervalidasi.

Finish Notify divalidasi menggunakan public key DANA untuk merchant payment yang ditemukan dari `originalPartnerReferenceNo`. Aplikasi memeriksa `X-PARTNER-ID`, `merchantId`, signature SNAP, referensi DANA, nominal, currency, dan transisi status. Status `00` menjadi `paid`; status `05` menjadi `expired`. Notifikasi yang sama aman diulang menggunakan unique key webhook DANA.

## Catatan path webhook

Dokumentasi Hosted Checkout DANA menyebut path ASPI `/v1.0/debit/notify`, sementara deployment ini menggunakan `/api/v1/webhooks/dana` sesuai kebutuhan gateway. SNAP signature mencakup relative path. Sebelum UAT, konfirmasikan path yang DANA gunakan untuk menandatangani callback dan set `DANA_WEBHOOK_RELATIVE_PATH` sesuai nilai itu; URL notifikasi yang didaftarkan harus sama.

## Pengujian

```powershell
php artisan test tests/Feature/DanaHostedCheckoutTest.php --compact
```

Test memakai `Http::fake()` dan signature service tiruan; tidak ada request yang dikirim ke Sandbox. Setelah credential dan callback dikonfigurasi di lingkungan Sandbox, lakukan UAT melalui Merchant Portal DANA dan gunakan `webRedirectUrl` dari respons payment untuk mensimulasikan pembayaran.
