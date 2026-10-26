<?php

namespace Tests\Feature;

use App\Domain\Finance\ImportWorkbook;
use App\Domain\Finance\OpeningBalanceImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class OpeningBalanceImportLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_import_after_activation_returns_bangla_lock_message(): void
    {
        Storage::fake('local');
        app()->setLocale('bn');

        $preparer = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('finance')->create();
        User::factory()->dealer()->create(['dealer_code' => 'D-LOCALE']);

        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview($preparer, 'opening_balances', $this->workbook([
            ['D-LOCALE', 'LOCALE-101', '2026-01-01', '100', '0', '0', '0'],
        ]), 'Locale opening statement');

        $this->enableFinancialPosting();

        try {
            $imports->queue($reviewer, $id, 'Should stay locked after activation');
            $this->fail('Expected ValidationException when opening import is locked');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_import_opening_locked', [], 'bn'),
                $exception->errors()['import'][0] ?? null,
            );
        }
    }

    public function test_opening_balance_order_note_follows_bangla_locale(): void
    {
        Storage::fake('local');
        app()->setLocale('bn');
        config(['madina.financial_activation' => false]);

        $preparer = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('finance')->create();
        User::factory()->dealer()->create(['dealer_code' => 'D-OB-BN', 'locale' => 'bn']);

        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview($preparer, 'opening_balances', $this->workbook([
            ['D-OB-BN', 'OB-BN-101', '2026-01-01', '0', '0', '200', '80'],
        ]), 'Locale historical statement');
        $imports->apply($reviewer, $id, 'Reviewed historical Bangla notes');

        $this->assertDatabaseHas('orders', [
            'number' => 'OPEN-OB-BN-101',
            'note' => __('portal.ob_order_note', [], 'bn'),
        ]);
        $this->assertDatabaseHas('payment_claims', [
            'reference' => 'OPEN-OB-BN-101',
            'channel' => __('portal.opening_balance', [], 'bn'),
            'reason' => __('portal.ob_claim_reason', [], 'bn'),
        ]);
        $this->assertTrue(
            DB::table('journal_entries')
                ->where('description', __('portal.ob_journal_approved', ['reference' => 'OB-BN-101'], 'bn'))
                ->exists()
        );
    }

    /** @param list<array<mixed>> $rows */
    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'madina-import-locale-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Data');
        $writer->addRow(Row::fromValues(ImportWorkbook::Columns['opening_balances']));
        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn ($value) => $value instanceof Cell ? $value : Cell::fromValue($value), $row)));
        }
        $writer->close();
        try {
            return UploadedFile::fake()->createWithContent('source.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
