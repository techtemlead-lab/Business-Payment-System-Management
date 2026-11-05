<?php

namespace Tests\Unit;

use App\Support\LocalDigits;
use PHPUnit\Framework\TestCase;

class LocalDigitsTest extends TestCase
{
    public function test_bangla_pages_get_bangla_digits(): void
    {
        $this->assertSame('১,২৫০.৫০', LocalDigits::text('1,250.50', 'bn'));
        $this->assertSame('২০২৬', LocalDigits::text(2026, 'bn'));
    }

    public function test_english_pages_keep_english_digits(): void
    {
        $this->assertSame('1,250.50', LocalDigits::text('1,250.50', 'en'));
    }

    public function test_empty_values_stay_empty(): void
    {
        $this->assertSame('', LocalDigits::text(null, 'bn'));
    }
}
