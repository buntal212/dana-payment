<?php

namespace App\Enums;

enum DanaSandboxPaymentMethod: string
{
    case DanaWallet = 'dana_wallet';
    case Qris = 'qris';
    case VaBri = 'va_bri';
    case VaPani = 'va_pani';
    case VaCimb = 'va_cimb';
    case VaBtpn = 'va_btpn';

    public function payMethod(): string
    {
        return match ($this) {
            self::DanaWallet => 'BALANCE',
            self::Qris => 'NETWORK_PAY',
            self::VaBri, self::VaPani, self::VaCimb, self::VaBtpn => 'VIRTUAL_ACCOUNT',
        };
    }

    public function payOption(): string
    {
        return match ($this) {
            self::DanaWallet => '',
            self::Qris => 'NETWORK_PAY_PG_QRIS',
            self::VaBri => 'VIRTUAL_ACCOUNT_BRI',
            self::VaPani => 'VIRTUAL_ACCOUNT_PANI',
            self::VaCimb => 'VIRTUAL_ACCOUNT_CIMB',
            self::VaBtpn => 'VIRTUAL_ACCOUNT_BTPN',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DanaWallet => 'DANA Wallet',
            self::Qris => 'QRIS',
            self::VaBri => 'Virtual Account BRI',
            self::VaPani => 'Virtual Account PANI',
            self::VaCimb => 'Virtual Account CIMB',
            self::VaBtpn => 'Virtual Account BTPN',
        };
    }

    public function requiresExternalStore(): bool
    {
        return $this === self::Qris;
    }
}
