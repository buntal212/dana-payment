<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifikasi Pembayaran</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { align-items: center; background: radial-gradient(circle at top, #eff6ff, #f8fafc 48%, #eef2ff); color: #172554; display: flex; justify-content: center; margin: 0; min-height: 100vh; padding: 24px; }
        main { background: rgba(255, 255, 255, .96); border: 1px solid #dbeafe; border-radius: 24px; box-shadow: 0 20px 45px rgba(30, 64, 175, .12); max-width: 520px; padding: 42px 32px; text-align: center; width: 100%; }
        .indicator { align-items: center; background: #eff6ff; border-radius: 999px; display: flex; height: 68px; justify-content: center; margin: 0 auto 24px; width: 68px; }
        .spinner { animation: rotate 1s linear infinite; border: 4px solid #bfdbfe; border-radius: 50%; border-top-color: #2563eb; height: 30px; width: 30px; }
        h1 { color: #172554; font-size: clamp(1.5rem, 5vw, 2rem); letter-spacing: -.025em; margin: 0 0 12px; }
        p { color: #475569; font-size: 1rem; line-height: 1.65; margin: 0; }
        .note { background: #f8fafc; border-radius: 12px; color: #64748b; font-size: .875rem; margin-top: 28px; padding: 14px 16px; }
        @keyframes rotate { to { transform: rotate(360deg); } }
        @media (max-width: 480px) { main { border-radius: 20px; padding: 34px 24px; } }
    </style>
</head>
<body>
    <main aria-labelledby="payment-verification-title">
        <div class="indicator" aria-hidden="true"><div class="spinner"></div></div>
        <h1 id="payment-verification-title">Pembayaran sedang diverifikasi</h1>
        <p>Kami sedang menerima konfirmasi aman dari penyedia pembayaran. Status transaksi akan diperbarui setelah verifikasi selesai.</p>
        <p class="note">Anda dapat menutup halaman ini dan kembali ke aplikasi merchant.</p>
    </main>
</body>
</html>
