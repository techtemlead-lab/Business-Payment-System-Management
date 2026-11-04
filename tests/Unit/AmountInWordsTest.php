<?php

namespace Tests\Unit;

use App\Support\AmountInWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountInWordsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function amounts(): array
    {
        return [
            'lakh with hundreds' => ['125300.00', 'এক লাখ পঁচিশ হাজার তিনশত টাকা মাত্র', 'One Lakh Twenty Five Thousand Three Hundred Taka only'],
            'poisha' => ['7.50', 'সাত টাকা পঞ্চাশ পয়সা মাত্র', 'Seven Taka and Fifty Poisha only'],
            'irregular tens' => ['99', 'নিরানব্বই টাকা মাত্র', 'Ninety Nine Taka only'],
            'large crore' => ['2500000000.00', 'দুইশত পঞ্চাশ কোটি টাকা মাত্র', 'Two Hundred Fifty Crore Taka only'],
            'zero' => ['0.00', 'শূন্য টাকা মাত্র', 'Zero Taka only'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amounts_are_written_in_bangla_and_english(string $amount, string $bangla, string $english): void
    {
        $this->assertSame($bangla, AmountInWords::taka($amount, 'bn'));
        $this->assertSame($english, AmountInWords::taka($amount, 'en'));
    }
}
