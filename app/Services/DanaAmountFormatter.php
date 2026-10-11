<?php

namespace App\Services;

use LogicException;

class DanaAmountFormatter
{
    /**
     * amount_minor IDR disimpan sebagai rupiah utuh; DANA mengharuskan dua digit
     * desimal pada nilai wire, misalnya 10000 menjadi "10000.00".
     */
    public function formatIdr(int $amountMinor): string
    {
        if ($amountMinor < 1) {
            throw new LogicException('Nominal IDR harus lebih besar dari nol.');
        }

        return $amountMinor.'.00';
    }

    public function matchesIdr(int $amountMinor, string $providerValue): bool
    {
        try {
            return hash_equals($this->formatIdr($amountMinor), $providerValue);
        } catch (LogicException) {
            return false;
        }
    }
}
