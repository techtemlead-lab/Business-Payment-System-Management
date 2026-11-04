<?php

namespace Tests\Unit;

use App\Support\Money;
use App\Support\SpreadsheetCell;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_cents_and_decimal_round_trip(): void
    {
        $this->assertSame(1250, Money::cents('12.50'));
        $this->assertSame('12.50', Money::decimal(1250));
        $this->assertSame(0, Money::cents('0.00'));
    }

    public function test_cents_accepts_company_totals_wider_than_one_column(): void
    {
        $this->assertSame(13048000000000, Money::cents('130480000000.00'));
        $this->assertSame('130480000000.00', Money::decimal(13048000000000));
    }

    public function test_cents_rejects_amounts_outside_a_64_bit_cent_total(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::cents('10000000000000000.00');
    }

    public function test_spreadsheet_formula_neutralization(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", SpreadsheetCell::text('=HYPERLINK("x")'));
        $this->assertSame('12.50', SpreadsheetCell::text('12.50'));
    }
}
