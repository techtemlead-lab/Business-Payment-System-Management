<?php

namespace Tests\Feature;

use App\Domain\Finance\ImportWorkbook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
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

    public function test_import_template_instructions_follow_staff_bangla_locale(): void
    {
        $staff = User::factory()->staff()->create(['locale' => 'bn']);
        $path = tempnam(sys_get_temp_dir(), 'madina-tpl-');

        try {
            $response = $this->actingAs($staff)->onPortal('manage')
                ->get('/commands/operations/imports/templates/partners');
            $response->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            file_put_contents($path, $response->streamedContent());

            $reader = new Reader;
            $reader->open($path);
            $instructionRows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== __('portal.import_tpl_sheet_instructions', [], 'bn')) {
                    continue;
                }
                foreach ($sheet->getRowIterator() as $row) {
                    $instructionRows[] = (string) ($row->toArray()[0] ?? '');
                }
            }
            $reader->close();

            $this->assertContains(__('portal.import_tpl_title', [], 'bn'), $instructionRows);
            $this->assertContains(__('portal.import_tpl_no_overwrite', [], 'bn'), $instructionRows);
            $this->assertNotContains(__('portal.import_tpl_title', [], 'en'), $instructionRows);
        } finally {
            @unlink($path);
        }
    }
}
