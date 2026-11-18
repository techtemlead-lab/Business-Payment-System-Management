<?php

namespace Tests\Unit;

use App\Support\TakaText;
use PHPUnit\Framework\TestCase;

class TakaTextTest extends TestCase
{
    public function test_whole_amount_drops_the_zero_paisa(): void
    {
        $this->assertSame('৳1,20,00,000', TakaText::amount('12000000.00', 'en'));
        $this->assertSame('৳3,00,000', TakaText::amount('300000', 'en'));
        $this->assertSame('৳0', TakaText::amount(0, 'en'));
    }

    public function test_fraction_is_kept(): void
    {
        $this->assertSame('৳1,234.50', TakaText::amount('1234.50', 'en'));
        $this->assertSame('৳999.05', TakaText::amount('999.05', 'en'));
    }

    public function test_lakh_and_crore_grouping(): void
    {
        $this->assertSame('৳1,23,45,678', TakaText::amount('12345678', 'en'));
        $this->assertSame('৳12,345', TakaText::amount(12345, 'en'));
    }

    public function test_bangla_digits(): void
    {
        $this->assertSame('৳৩০,০০,০০,০০০', TakaText::amount('300000000.00', 'bn'));
        $this->assertSame('৳১,২৩৪.৫০', TakaText::amount('1234.50', 'bn'));
    }

    public function test_words_for_one_lakh_and_below(): void
    {
        $this->assertSame('১ লাখ টাকা', TakaText::words('100000.00', 'bn'));
        $this->assertSame('৫ লাখ টাকা', TakaText::words('500000', 'bn'));
        $this->assertNull(TakaText::words('99999.99', 'bn'));
        $this->assertNull(TakaText::words('0', 'en'));
    }

    public function test_words_for_crore_amounts(): void
    {
        $this->assertSame('৩০ কোটি টাকা', TakaText::words('300000000.00', 'bn'));
        $this->assertSame('১ কোটি ২০ লাখ টাকা', TakaText::words('12000000.00', 'bn'));
        $this->assertSame('১,২৫০ কোটি টাকা', TakaText::words('12500000000', 'bn'));
    }

    public function test_english_words(): void
    {
        $this->assertSame('30 crore taka', TakaText::words('300000000', 'en'));
        $this->assertSame('1 crore 20 lakh taka', TakaText::words('12000000.00', 'en'));
        $this->assertSame('5 lakh taka', TakaText::words('500000', 'en'));
        $this->assertSame('1,250 crore taka', TakaText::words('12500000000', 'en'));
    }

    public function test_with_words_joins_amount_and_reading(): void
    {
        $this->assertSame('৳৩০,০০,০০,০০০ (৩০ কোটি টাকা)', TakaText::withWords('300000000', 'bn'));
        $this->assertSame('৳1,234.50', TakaText::withWords('1234.50', 'en'));
    }
}
