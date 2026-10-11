<?php

namespace Tests\Unit;

use App\Services\DanaAmountFormatter;
use PHPUnit\Framework\TestCase;

class DanaAmountFormatterTest extends TestCase
{
    public function test_it_formats_whole_rupiah_as_dana_two_decimal_value_without_float(): void
    {
        $formatter = new DanaAmountFormatter;

        $this->assertSame('10000.00', $formatter->formatIdr(10_000));
        $this->assertSame('999999999999999.00', $formatter->formatIdr(999_999_999_999_999));
    }

    public function test_it_only_accepts_the_exact_dana_idr_wire_value(): void
    {
        $formatter = new DanaAmountFormatter;

        $this->assertTrue($formatter->matchesIdr(10_000, '10000.00'));
        $this->assertFalse($formatter->matchesIdr(10_000, '10000'));
        $this->assertFalse($formatter->matchesIdr(10_000, '10000.0'));
        $this->assertFalse($formatter->matchesIdr(10_000, '10000.01'));
    }
}
