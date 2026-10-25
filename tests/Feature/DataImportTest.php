<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Finance\DueAdjustments;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\ImportWorkbook;
use App\Domain\Finance\OpeningBalanceImport;
use App\Domain\Orders\OrderService;
use App\Jobs\ApplyDataImport;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DataImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.financial_activation' => false]);
    }

    public function test_guests_partners_and_view_only_finance_cannot_import(): void
    {
        $this->get('/company/operations/data-imports')->assertRedirect('/login');
        $this->actingAs(User::factory()->dealer()->create())->get('/company/operations/data-imports')->assertForbidden();
        $this->actingAs(User::factory()->staff('chairman')->create())->postJson('/api/v1/operations/imports/preview', [
            'type' => 'opening_balances', 'source_reference' => 'Approved source',
            'file' => $this->workbook('opening_balances', [['UNKNOWN', 'REF-001', '2026-01-01', '0', '0', '0', '0']]),
        ])->assertForbidden();
    }

    public function test_versioned_import_api_returns_json_without_an_accept_header(): void
    {
        $this->actingAs(User::factory()->staff()->create())->get('/api/v1/operations/imports')
            ->assertOk()->assertJsonCount(3, 'data.types');
    }

    public function test_partner_preview_changes_no_accounts_and_apply_preserves_existing_records(): void
    {
        Storage::fake('local');
        $admin = User::factory()->staff()->create();
        $sponsor = User::factory()->dealer()->create(['dealer_code' => 'SPONSOR']);
        $response = $this->actingAs($admin)->postJson('/api/v1/operations/imports/preview', [
            'type' => 'partners', 'source_reference' => 'Approved partner register', 'file' => $this->workbook('partners', [
                ['D-101', 'নতুন ডিলার', 'new@example.test', '01700000001', 'dealer', 'SPONSOR', 'Rangpur', 'Rangpur'],
            ]),
        ])->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseMissing('users', ['dealer_code' => 'D-101']);
        $this->actingAs($admin)->postJson('/api/v1/operations/imports/'.$id.'/apply', ['reason' => 'Checked against source register', 'confirmed' => true])->assertOk();
        $partner = User::where('dealer_code', 'D-101')->firstOrFail();
        $this->assertSame($sponsor->id, $partner->sponsor_id);
        $this->assertFalse($partner->directory_visible);
        $this->assertDatabaseHas('referral_tree_paths', ['ancestor_id' => $sponsor->id, 'descendant_id' => $partner->id, 'depth' => 1]);
        $this->assertDatabaseHas('users', ['id' => $sponsor->id, 'dealer_code' => 'SPONSOR']);
        $this->postJson('/api/v1/operations/imports/'.$id.'/apply', ['reason' => 'Repeated submission', 'confirmed' => true])->assertOk();
        $this->assertSame(1, User::where('dealer_code', 'D-101')->count());
        $this->get('/company/operations/data-imports?batch='.$id)->assertOk();
    }

    public function test_product_import_creates_unpublished_stock_with_a_movement(): void
    {
        Storage::fake('local');
        $admin = User::factory()->staff()->create();
        $id = app(OpeningBalanceImport::class)->preview($admin, 'products',
            $this->workbook('products', [['OLD-101', 'Imported product', 'Wellness', 'pack', '200', '100', '120', '60', '8', '10']]), 'Approved product register');
        $this->assertDatabaseMissing('products', ['sku' => 'OLD-101']);
        app(OpeningBalanceImport::class)->apply($admin, $id, 'Checked prices and opening inventory');
        $this->assertDatabaseHas('products', ['sku' => 'OLD-101', 'stock' => 8, 'active' => false]);
        $this->assertDatabaseHas('stock_movements', ['kind' => 'opening_import', 'quantity' => 8, 'actor_id' => $admin->id]);
    }

    public function test_opening_entries_require_independent_approval_and_reconcile_without_new_earnings(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 19)->startOfDay());
        $preparer = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('finance')->create();
        $partner = User::factory()->dealer()->create(['dealer_code' => 'D-OPEN']);
        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview($preparer, 'opening_balances', $this->workbook('opening_balances', [
            ['D-OPEN', 'OPEN-101', '2026-10-01', '১০০.০০', '50.00', '200.00', '80.00'],
        ]), 'Client closing statement');
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->actingAs($preparer)->postJson('/api/v1/operations/imports/'.$id.'/apply', ['reason' => 'Checked the opening statement', 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('import');
        $this->actingAs($reviewer)->postJson('/api/v1/operations/imports/'.$id.'/apply', ['reason' => 'Independent statement review', 'confirmed' => true])->assertOk();
        $this->assertDatabaseHas('wallets', ['user_id' => $partner->id, 'available' => '100.00', 'held' => '50.00', 'commission_held' => '50.00']);
        $order = Order::where('user_id', $partner->id)->firstOrFail();
        $this->assertSame('120.00', $order->outstanding());
        $this->assertTrue($order->is_opening_balance);
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['internally_reconciled']);
        $this->assertFalse(config('madina.financial_activation'));
        $this->enableFinancialPosting();
        $order->update(['paid' => '200.00']);
        $this->assertSame([], app(CommissionPosting::class)->process($order));
        $this->assertSame(0, app(CommissionQualification::class)->monthlyVolume($partner->id, '2026-10'));
        $this->assertDatabaseCount('commission_batches', 0);
    }

    public function test_opening_hold_release_is_gated_idempotent_and_balanced(): void
    {
        Storage::fake('local');
        $preparer = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('finance')->create();
        $partner = User::factory()->dealer()->create(['dealer_code' => 'D-HOLD']);
        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview($preparer, 'opening_balances', $this->workbook('opening_balances', [
            ['D-HOLD', 'HOLD-101', '2026-01-01', '100', '50', '0', '0'],
        ]), 'Client closing statement');
        $imports->apply($reviewer, $id, 'Independent opening review');
        $entry = DB::table('opening_balance_entries')->first();
        $url = '/api/v1/operations/imports/opening-holds/'.$entry->id.'/release';
        $this->actingAs($reviewer)->postJson($url, ['reason' => 'Client authorized hold release'])->assertUnprocessable();
        $this->enableFinancialPosting();
        $this->postJson($url, ['reason' => 'Client authorized hold release'])->assertOk();
        $this->postJson($url, ['reason' => 'Repeated release request'])->assertOk();
        $this->assertDatabaseHas('wallets', ['user_id' => $partner->id, 'available' => '150.00', 'held' => '0.00', 'commission_held' => '0.00']);
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['internally_reconciled']);
    }

    public function test_invalid_duplicates_block_the_entire_import(): void
    {
        Storage::fake('local');
        $admin = User::factory()->staff()->create();
        $id = app(OpeningBalanceImport::class)->preview($admin, 'partners', $this->workbook('partners', [
            ['D-DUP', 'First dealer', 'first@example.test', '01700000001', 'dealer', '', '', ''],
            ['D-DUP', 'Second dealer', 'second@example.test', '01700000002', 'dealer', '', '', ''],
        ]), 'Duplicate source register');
        $this->assertDatabaseHas('data_import_batches', ['id' => $id, 'status' => 'invalid']);
        $this->actingAs($admin)->postJson('/api/v1/operations/imports/'.$id.'/apply', ['reason' => 'Attempt invalid import', 'confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['dealer_code' => 'D-DUP']);
    }

    public function test_formulas_and_changed_originals_are_rejected(): void
    {
        Storage::fake('local');
        $admin = User::factory()->staff()->create();
        $file = $this->workbook('partners', [[new FormulaCell('=HYPERLINK("https://example.test")'), 'Unsafe dealer', 'new@example.test', '01700000001', 'dealer', '', '', '']]);
        $this->actingAs($admin)->postJson('/api/v1/operations/imports/preview', ['type' => 'partners', 'source_reference' => 'Formula source', 'file' => $file])->assertUnprocessable()->assertJsonValidationErrors('file');
        $id = app(OpeningBalanceImport::class)->preview($admin, 'partners', $this->workbook('partners', [
            ['D-SAFE', 'Safe dealer', 'safe@example.test', '01700000002', 'dealer', '', '', ''],
        ]), 'Approved source register');
        Storage::disk('local')->put(DB::table('data_import_batches')->where('id', $id)->value('file_path'), 'changed');
        $this->postJson('/api/v1/operations/imports/'.$id.'/apply', ['reason' => 'Apply changed workbook', 'confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['dealer_code' => 'D-SAFE']);
    }

    public function test_opening_import_is_locked_after_financial_activation(): void
    {
        Storage::fake('local');
        $preparer = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('finance')->create();
        User::factory()->dealer()->create(['dealer_code' => 'D-LOCK']);
        $id = app(OpeningBalanceImport::class)->preview($preparer, 'opening_balances', $this->workbook('opening_balances', [
            ['D-LOCK', 'LOCK-101', '2026-01-01', '100', '0', '0', '0'],
        ]), 'Client closing statement');
        $this->enableFinancialPosting();
        $this->actingAs($reviewer)->postJson('/api/v1/operations/imports/'.$id.'/apply', ['reason' => 'Opening import after activation', 'confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseCount('opening_balance_entries', 0);
    }

    public function test_background_import_is_queued_once_and_applies_atomically(): void
    {
        Storage::fake('local');
        Queue::fake([ApplyDataImport::class]);
        config(['queue.default' => 'database']);
        $admin = User::factory()->staff()->create();
        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview($admin, 'partners', $this->workbook('partners', [
            ['D-QUEUE', 'Queued partner', 'queued@example.test', '01700000005', 'dealer', '', '', ''],
        ]), 'Approved source register');
        $this->actingAs($admin)->postJson('/api/v1/operations/imports/'.$id.'/apply', ['reason' => 'Background import review', 'confirmed' => true])->assertAccepted();
        $this->postJson('/api/v1/operations/imports/'.$id.'/apply', ['reason' => 'Repeated request', 'confirmed' => true])->assertAccepted();
        Queue::assertPushed(ApplyDataImport::class, 1);
        $this->assertDatabaseHas('data_import_batches', ['id' => $id, 'status' => 'queued']);
        $this->assertDatabaseMissing('users', ['dealer_code' => 'D-QUEUE']);
        (new ApplyDataImport($id, $admin->id, 'Background import review'))->handle($imports);
        $this->assertDatabaseHas('users', ['dealer_code' => 'D-QUEUE']);
        $this->assertDatabaseHas('data_import_batches', ['id' => $id, 'status' => 'applied']);
    }

    public function test_changed_records_after_preview_do_not_allow_partial_import(): void
    {
        Storage::fake('local');
        $admin = User::factory()->staff()->create();
        $id = app(OpeningBalanceImport::class)->preview($admin, 'partners', $this->workbook('partners', [
            ['D-FIRST', 'First partner', 'first@example.test', '01700000006', 'dealer', '', '', ''],
            ['D-SECOND', 'Second partner', 'second@example.test', '01700000007', 'dealer', '', '', ''],
        ]), 'Approved source register');
        User::factory()->dealer()->create(['dealer_code' => 'D-SECOND']);
        $this->actingAs($admin)->postJson('/api/v1/operations/imports/'.$id.'/apply', ['reason' => 'Data changed since preview', 'confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['dealer_code' => 'D-FIRST']);
        $this->assertSame(1, User::where('dealer_code', 'D-SECOND')->count());
    }

    public function test_database_import_job_and_queued_status_rollback_together(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
        $admin = User::factory()->staff()->create();
        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview($admin, 'partners', $this->workbook('partners', [
            ['D-ATOMIC', 'Atomic partner', 'atomic@example.test', '01700000008', 'dealer', '', '', ''],
        ]), 'Approved source register');
        DB::beginTransaction();
        try {
            $imports->queue($admin, $id, 'Approved atomic background import');
            $this->assertDatabaseHas('data_import_batches', ['id' => $id, 'status' => 'queued']);
            $this->assertDatabaseCount('jobs', 1);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseHas('data_import_batches', ['id' => $id, 'status' => 'preview']);
        $this->assertDatabaseCount('jobs', 0);
    }

    #[TestWith(['0.00', '120.00'])]
    #[TestWith(['50.00', '70.00'])]
    public function test_historical_order_collections_and_credits_reconcile_without_commissions(string $credit, string $collection): void
    {
        Storage::fake('local');
        $preparer = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('finance')->create();
        $partner = User::factory()->dealer()->create(['dealer_code' => 'D-DUES']);
        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview($preparer, 'opening_balances', $this->workbook('opening_balances', [
            ['D-DUES', 'DUES-101', '2026-01-01', '0', '0', '200', '80'],
        ]), 'Historical receivable statement');
        $imports->apply($reviewer, $id, 'Reviewed the historical statement');
        $order = Order::where('user_id', $partner->id)->firstOrFail();
        $this->enableFinancialPosting();
        if ($credit !== '0.00') {
            $adjustment = app(DueAdjustments::class)->request($preparer, $order, [
                'kind' => 'credit', 'amount' => $credit, 'reference' => 'HISTORICAL-CREDIT',
                'request_key' => (string) Str::uuid(), 'reason' => 'Approved historical receivable correction',
            ]);
            app(DueAdjustments::class)->review($reviewer, $adjustment, 'approved', 'Independent correction review');
        }
        $orders = app(OrderService::class);
        $orders->claim($partner, $order->fresh(), ['channel' => 'Bank', 'reference' => 'HISTORICAL-COLLECTION', 'amount' => $collection]);
        $claim = DB::table('payment_claims')->where('reference', 'HISTORICAL-COLLECTION')->value('id');
        $orders->verify($reviewer, $claim, true, 'Verified historical balance collection');
        $this->assertSame('0.00', $order->fresh()->outstanding());
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['internally_reconciled']);
        $this->assertDatabaseCount('commission_batches', 0);
    }

    public function test_zero_opening_can_be_evidenced_by_excel_without_importing_or_enabling_posting(): void
    {
        Storage::fake('local');
        $preparer = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('finance')->create();
        $response = $this->actingAs($preparer)->postJson('/api/v1/operations/opening-balances', [
            'wallet_available' => '0.00', 'wallet_held' => '0.00', 'order_paid' => '0.00', 'withdrawal_paid' => '0.00',
            'source_reference' => 'Client approved fresh-start statement',
            'evidence' => $this->workbook('opening_balances', [['NEW-BUSINESS', 'ZERO-101', '2026-01-01', '0', '0', '0', '0']]),
        ])->assertOk();
        $this->actingAs($reviewer)->postJson('/api/v1/operations/opening-balances/'.$response->json('data.id').'/verify')->assertOk();
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['opening_verified']);
        $this->assertDatabaseCount('opening_balance_entries', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertFalse(config('madina.financial_activation'));
    }

    /** @param list<array<mixed>> $rows */
    private function workbook(string $type, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'madina-import-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Data');
        $writer->addRow(Row::fromValues(ImportWorkbook::Columns[$type]));
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
