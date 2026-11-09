<?php

namespace Tests\Feature;

use App\Domain\Finance\ImportWorkbook;
use App\Domain\Finance\OpeningBalanceImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * A partner brought in from the partner register needs the same refer code a partner made
 * on the form gets, or they have no referral link, QR code or join poster to share.
 */
class ImportedPartnerReferCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.financial_activation' => false]);
        $this->seedClassicPlaceCodes();
        Storage::fake('local');
    }

    public function test_an_imported_partner_can_share_a_referral_code(): void
    {
        $admin = User::factory()->staff()->create();
        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview($admin, 'partners', $this->workbook([
            ['D-101', 'Imported dealer', 'imported@example.test', '01700000001', 'dealer', '', 'MNHC-102'],
        ]), 'Approved partner register');
        $imports->apply($admin, $id, 'Checked against the register');

        $partner = User::where('dealer_code', 'D-101')->firstOrFail();
        $this->assertSame('D-101', $partner->refer_code);
        $this->actingAs($partner)->onPortal('dealer')->get('/referral-qr.svg')->assertOk();
    }

    public function test_a_code_someone_already_uses_as_a_refer_code_is_refused_in_the_preview(): void
    {
        $admin = User::factory()->staff()->create();
        User::factory()->dealer()->create(['refer_code' => 'D-101']);

        $id = app(OpeningBalanceImport::class)->preview($admin, 'partners', $this->workbook([
            ['D-101', 'Imported dealer', 'imported@example.test', '01700000001', 'dealer', '', 'MNHC-102'],
        ]), 'Approved partner register');

        $this->assertDatabaseHas('data_import_batches', ['id' => $id, 'status' => 'invalid']);
        $rows = json_decode((string) DB::table('data_import_batches')->where('id', $id)->value('rows'), true);
        $this->assertContains(__('portal.acct_val_refer_taken'), $rows[0]['errors']);
    }

    /** @param list<list<string>> $rows */
    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'madina-import-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Data');
        $writer->addRow(Row::fromValues(ImportWorkbook::Columns['partners']));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        try {
            return UploadedFile::fake()->createWithContent('source.xlsx', (string) file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
