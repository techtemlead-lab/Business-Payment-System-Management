<?php

namespace Tests\Unit;

use App\Support\Money;
use App\Support\SpreadsheetCell;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_cents_and_decimal_round_trip(): void
    {
        $this->assertSame(1250, Money::cents('12.50'));
        $this->assertSame('12.50', Money::decimal(1250));
        $this->assertSame(0, Money::cents('0.00'));
    }

    public function test_spreadsheet_formula_neutralization(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", SpreadsheetCell::text('=HYPERLINK("x")'));
        $this->assertSame('12.50', SpreadsheetCell::text('12.50'));
    }
}
