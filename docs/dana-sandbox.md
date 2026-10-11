# DANA Sandbox Hosted Checkout

Integrasi ini memakai Create Order Hosted Checkout DANA dan menerima Finish Notify pada `POST /api/v1/webhooks/dana`. Tidak ada credential yang disimpan dalam repository atau `.env` contoh.

## Konfigurasi per merchant

Simpan satu baris aktif pada `merchant_payment_configs` dengan `provider=dana` dan `environment=sandbox`. Kolom `partner_id` berisi `X-PARTNER-ID`, sedangkan `callback_url` berisi URL `PAY_RETURN` HTTPS milik merchant.

Simpan JSON berikut melalui jalur administratif tepercaya pada `encrypted_credentials`; cast Eloquent akan mengenkripsinya dengan `APP_KEY`:

```json
{
  "merchant_id": "DANA merchant ID",
  "private_key": "PEM private key merchant",
  "dana_webhook_public_key": "PEM public key DANA Sandbox untuk verifikasi webhook",
  "origin": "domain-aplikasi.example",
  "channel_id": "CHANNEL-ID dari onboarding",
  "notification_url": "https://gateway.example/api/v1/webhooks/dana",
  "mcc": "MCC merchant"
}
```

Nilai `notification_url` harus dapat diakses publik melalui HTTPS dan persis sama dengan endpoint yang didaftarkan di Portal DANA. `dana_webhook_public_key` harus berasal dari sumber resmi DANA dan **bukan** public key pasangan private key merchant. Dokumentasi autentikasi DANA saat ini mempublikasikan public key Sandbox; operator harus mengambil serta memverifikasinya dari [dokumentasi resmi DANA](https://dashboard.dana.id/api-docs-v2/guide/authentication/authentication-asymmetric) sebelum mengaktifkan konfigurasi. Jangan memasukkan credential melalui argumen Artisan, source code, log, atau repository.

Konfigurasi lama dengan kunci JSON `dana_public_key` sengaja tidak lagi diterima. Ganti dengan `dana_webhook_public_key` yang telah diverifikasi. Tanpa kunci tersebut, resolver menolak membuat checkout dan memproses webhook; aplikasi tidak melakukan fallback ke credential `.env` atau merchant lain.

Gunakan perintah server-side berikut untuk membuat atau memperbarui konfigurasi merchant. Perintah meminta nilai non-rahasia secara interaktif dan membaca isi PEM dari berkas lokal tanpa mencetaknya:

```powershell
php artisan merchant:dana:configure larissama
```

Konfigurasi aplikasi yang tidak rahasia tersedia melalui environment, tanpa mengubah `.env` yang dilacak:

```text
DANA_SANDBOX_BASE_URL=https://api.sandbox.dana.id
DANA_TIMEOUT=8
DANA_CONNECT_TIMEOUT=3
DANA_WEBHOOK_RELATIVE_PATH=/v1.0/debit/notify
```

## Alur

`POST /api/v1/payments` membuat payment berstatus `pending`, kemudian meminta Create Order DANA dengan `partnerReferenceNo` berupa `payments.public_id`. Respons hanya disimpan bila `responseCode` adalah `2005400` dan memiliki `referenceNo` serta `webRedirectUrl`. Status tetap `pending` hingga Finish Notify tervalidasi.

Finish Notify divalidasi menggunakan public key DANA untuk konfigurasi merchant yang ditemukan dari `originalPartnerReferenceNo`, `provider`, dan `environment`. Aplikasi memeriksa `X-PARTNER-ID`, `merchantId`, signature SNAP, referensi DANA, nominal, currency, dan transisi status. Status `00` menjadi `paid`; status `05` menjadi `expired`.

`originalReferenceNo` diperlakukan sebagai referensi transaksi DANA, bukan ID event webhook: dokumentasi yang diaudit tidak menjamin nilai itu unik per pengiriman webhook. Karena itu `provider_event_id` dibiarkan `NULL`. Callback diserialkan dengan penguncian baris payment dan deduplikasi dilakukan berdasarkan SHA-256 dari payload mentah pada payment yang sama. Pembaruan status, event pembayaran, dan catatan webhook valid berada dalam satu transaksi database. Pengiriman ulang payload yang sama tidak menciptakan transisi kedua; retry setelah penolakan dapat diproses kembali.

## Redirect pelanggan dan path webhook

Set `callback_url` merchant ke URL HTTPS halaman redirect aplikasi, misalnya `https://gateway.example/payment/finish`. Halaman ini hanya menampilkan bahwa pembayaran sedang diverifikasi dan tidak memercayai parameter URL atau mengubah status payment.

Endpoint Laravel penerima callback tetap `POST /api/v1/webhooks/dana`, tetapi path yang disertakan pada string-to-sign Finish Notify secara default adalah `/v1.0/debit/notify`. SNAP signature mencakup relative path. Jika kontrak DANA merchant Anda secara eksplisit menetapkan path berbeda, gunakan `DANA_WEBHOOK_RELATIVE_PATH` sebagai override dan uji signature sebelum UAT.

## Pengujian

```powershell
php artisan test tests/Feature/DanaHostedCheckoutTest.php --compact
```

Test memakai `Http::fake()` dan signature service tiruan; tidak ada request yang dikirim ke Sandbox. Setelah credential dan callback dikonfigurasi di lingkungan Sandbox, lakukan UAT melalui Merchant Portal DANA dan gunakan `webRedirectUrl` dari respons payment untuk mensimulasikan pembayaran.

Sebelum deployment, jalankan pengujian feature dan pastikan worker database produksi memakai MySQL/InnoDB agar `lockForUpdate()` memberi serialisasi callback lintas proses:

```powershell
php artisan test tests/Feature/DanaHostedCheckoutTest.php --compact
```
