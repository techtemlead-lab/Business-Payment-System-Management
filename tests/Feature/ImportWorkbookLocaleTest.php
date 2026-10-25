<?php

namespace Tests\Feature;

use App\Domain\Finance\ImportWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportWorkbookLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_balance_missing_partner_returns_bangla_row_error(): void
    {
        app()->setLocale('bn');

        [$rows] = app(ImportWorkbook::class)->validate('opening_balances', [
            [
                'row' => 2,
                'data' => [
                    'dealer_code' => 'MISSING-1',
                    'reference' => 'REF-LOCALE-1',
                    'as_of_date' => '2026-01-01',
                    'available_commission' => '10.00',
                    'held_commission' => '0.00',
                    'historical_order_total' => '0.00',
                    'historical_order_paid' => '0.00',
                ],
            ],
        ]);

        $this->assertContains(
            __('portal.err_wb_partner_missing', [], 'bn'),
            $rows[0]['errors'] ?? [],
        );
    }
}
