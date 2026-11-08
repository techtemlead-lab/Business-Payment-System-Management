<?php

namespace Tests\Feature;

use App\Domain\Finance\StatementImports;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class StatementImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function finance(): User
    {
        return User::factory()->staff('finance')->create(['status' => 'active']);
    }

    private function claim(string $reference, string $amount, string $channel = 'bKash', string $status = 'pending'): int
    {
        $buyer = User::factory()->dealer()->create(['status' => 'active']);
        $order = Order::create([
            'number' => 'MN-'.Str::upper(Str::random(8)), 'user_id' => $buyer->id, 'status' => 'submitted',
            'total' => $amount, 'paid' => '0.00', 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);

        return DB::table('payment_claims')->insertGetId([
            'order_id' => $order->id, 'channel' => $channel, 'reference' => $reference, 'amount' => $amount,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function csv(string $body, string $name = 'statement.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    /** Upload a CSV and map it: columns are Date, TrxID, Details, Amount. */
    private function importCsv(User $actor, string $body, string $channel = 'bKash'): string
    {
        $id = app(StatementImports::class)->upload($actor, $channel, 'October statement', $this->csv($body));
        app(StatementImports::class)->map($actor, $id, ['first_data_row' => 2, 'reference_column' => 1, 'amount_column' => 3, 'date_column' => 0, 'description_column' => 2]);

        return $id;
    }

    private function line(string $importId, string $reference): object
    {
        return DB::table('statement_import_lines')->where('statement_import_id', $importId)->where('reference', $reference)->orderBy('id')->first();
    }

    public function test_upload_shows_a_column_mapping_step_with_a_best_guess(): void
    {
        $finance = $this->finance();

        $response = $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/accounting/statements', [
            'channel' => 'bKash', 'source_reference' => 'October statement',
            'file' => $this->csv("Bank Asia statement\nDate,TrxID,Details,Amount\n2026-10-05,ABC123,Sent,1500.00\n"),
        ]);

        $id = DB::table('statement_imports')->value('id');
        $response->assertRedirect('/operations/accounting?tab=import&statement='.$id);
        $this->assertDatabaseCount('statement_import_lines', 0);
        $this->actingAs($finance)->onPortal('manage')->get('/operations/accounting?tab=import&statement='.$id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting')
                ->where('statement.status', 'awaiting_mapping')
                ->where('statement.guess.first_data_row', 3)
                ->where('statement.guess.reference_column', 1)
                ->where('statement.guess.amount_column', 3)
                ->has('statement.preview', 3));
    }

    public function test_excel_statements_can_be_imported_too(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Date', 'TrxID', 'Details', 'Amount']));
        $writer->addRow(Row::fromValues(['2026-10-05', 'XLS123', 'Sent', 1500.5]));
        $writer->close();
        $claim = $this->claim('XLS123', '1500.50');
        $finance = $this->finance();

        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/accounting/statements', [
            'channel' => 'bKash', 'source_reference' => 'October statement',
            'file' => new UploadedFile($path, 'statement.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertRedirect();
        $id = DB::table('statement_imports')->value('id');
        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/accounting/statements/'.$id.'/map', [
            'first_data_row' => 2, 'reference_column' => 1, 'amount_column' => 3,
        ])->assertRedirect();

        $this->assertSame($claim, $this->line($id, 'XLS123')->suggested_claim_id);
        $this->assertSame('suggested', $this->line($id, 'XLS123')->state);
        @unlink($path);
    }

    public function test_matches_suggested_by_reference_and_amount(): void
    {
        $exact = $this->claim('ABC123', '1500.00');
        $wrongAmount = $this->claim('DEF456', '999.00');
        $this->claim('GHI789', '200.00', 'Nagad');
        $finance = $this->finance();

        $id = $this->importCsv($finance, "Date,TrxID,Details,Amount\n2026-10-05,abc123,Sent,\"1,500.00\"\n2026-10-06,DEF456,Sent,1000\n2026-10-07,GHI789,Sent,200\n2026-10-08,NOPE000,Sent,50\n2026-10-09,,Fee,10\n2026-10-10,REV111,Out,-40\n");

        $this->assertSame('suggested', $this->line($id, 'ABC123')->state);
        $this->assertSame($exact, $this->line($id, 'ABC123')->suggested_claim_id);
        $this->assertSame('amount_differs', $this->line($id, 'DEF456')->state);
        $this->assertSame($wrongAmount, $this->line($id, 'DEF456')->suggested_claim_id);
        $this->assertSame('no_match', $this->line($id, 'GHI789')->state, 'A claim on another channel is not a match.');
        $this->assertSame('no_match', $this->line($id, 'NOPE000')->state);
        $this->assertSame('ignored', $this->line($id, 'REV111')->state);
        $summary = json_decode(DB::table('statement_imports')->where('id', $id)->value('summary'), true);
        $this->assertSame(['total' => 6, 'suggested' => 1, 'amount_differs' => 1, 'no_match' => 2, 'ignored' => 2, 'flagged' => 0], $summary);
    }

    public function test_bangla_digits_in_amounts_are_understood(): void
    {
        $this->claim('BN111', '1500.00');

        $id = $this->importCsv($this->finance(), "Date,TrxID,Details,Amount\n2026-10-05,BN111,Sent,১৫০০\n");

        $this->assertSame('suggested', $this->line($id, 'BN111')->state);
    }

    public function test_importing_and_confirming_never_approve_a_payment(): void
    {
        $claim = $this->claim('ABC123', '1500.00');
        $orderId = DB::table('payment_claims')->where('id', $claim)->value('order_id');
        $finance = $this->finance();
        $id = $this->importCsv($finance, "Date,TrxID,Details,Amount\n2026-10-05,ABC123,Sent,1500\n");
        $journal = DB::table('journal_entries')->count();

        $this->assertSame('pending', DB::table('payment_claims')->where('id', $claim)->value('status'), 'Importing must not approve.');

        $line = $this->line($id, 'ABC123');
        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/accounting/statement-lines/'.$line->id.'/confirm', [])->assertRedirect();

        $this->assertSame('confirmed', $this->line($id, 'ABC123')->state);
        $this->assertSame($finance->id, $this->line($id, 'ABC123')->decided_by);
        $stored = DB::table('payment_claims')->where('id', $claim)->first();
        $this->assertSame('pending', $stored->status, 'Confirming must not approve.');
        $this->assertNull($stored->verified_by);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertSame('0.00', (string) DB::table('orders')->where('id', $orderId)->value('paid'));
        $this->assertSame($journal, DB::table('journal_entries')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'statement_import.match_confirmed', 'actor_id' => $finance->id]);
    }

    public function test_every_suggestion_waits_for_a_person(): void
    {
        $this->claim('ABC123', '1500.00');

        $id = $this->importCsv($this->finance(), "Date,TrxID,Details,Amount\n2026-10-05,ABC123,Sent,1500\n");

        $this->assertNull($this->line($id, 'ABC123')->decided_by);
        $this->assertNull($this->line($id, 'ABC123')->decided_at);
        $this->assertDatabaseMissing('statement_import_lines', ['state' => 'confirmed']);
    }

    public function test_a_reference_used_twice_in_the_statement_is_flagged_as_possibly_fake(): void
    {
        $this->claim('DUP111', '1500.00');

        $id = $this->importCsv($this->finance(), "Date,TrxID,Details,Amount\n2026-10-05,DUP111,Sent,1500\n2026-10-06,DUP111,Sent,1500\n2026-10-06,OK222,Sent,20\n");

        $lines = DB::table('statement_import_lines')->where('reference', 'DUP111')->pluck('flags');
        $this->assertCount(2, $lines);
        foreach ($lines as $flags) {
            $this->assertContains(StatementImports::FlagInThisStatement, json_decode($flags, true));
        }
        $this->assertSame([], json_decode($this->line($id, 'OK222')->flags, true));
        $this->assertSame(2, json_decode(DB::table('statement_imports')->where('id', $id)->value('summary'), true)['flagged']);
    }

    public function test_a_reference_seen_in_an_earlier_statement_is_flagged(): void
    {
        $finance = $this->finance();
        $this->importCsv($finance, "Date,TrxID,Details,Amount\n2026-10-05,AGAIN1,Sent,1500\n");

        $second = $this->importCsv($finance, "Date,TrxID,Details,Amount\n2026-11-05,AGAIN1,Sent,1500\n");

        $this->assertContains(StatementImports::FlagEarlierStatement, json_decode($this->line($second, 'AGAIN1')->flags, true));
    }

    public function test_a_reference_on_more_than_one_claim_is_flagged(): void
    {
        $this->claim('TWICE9', '1500.00');
        $this->claim('TWICE9', '1500.00', 'Nagad', 'verified');

        $id = $this->importCsv($this->finance(), "Date,TrxID,Details,Amount\n2026-10-05,TWICE9,Sent,1500\n");

        $this->assertContains(StatementImports::FlagSeveralClaims, json_decode($this->line($id, 'TWICE9')->flags, true));
    }

    public function test_a_rejected_claim_does_not_count_as_a_second_use(): void
    {
        $this->claim('RETRY1', '1500.00');
        $this->claim('RETRY1', '1500.00', 'Nagad', 'rejected');

        $id = $this->importCsv($this->finance(), "Date,TrxID,Details,Amount\n2026-10-05,RETRY1,Sent,1500\n");

        $this->assertSame([], json_decode($this->line($id, 'RETRY1')->flags, true));
    }

    public function test_a_flagged_match_needs_a_note_before_it_can_be_confirmed(): void
    {
        $this->claim('DUP111', '1500.00');
        $finance = $this->finance();
        $id = $this->importCsv($finance, "Date,TrxID,Details,Amount\n2026-10-05,DUP111,Sent,1500\n2026-10-06,DUP111,Sent,1500\n");
        $line = $this->line($id, 'DUP111');

        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/operations/accounting/statement-lines/'.$line->id.'/confirm', [])->assertStatus(422);
        $this->assertSame('suggested', $this->line($id, 'DUP111')->state);

        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/operations/accounting/statement-lines/'.$line->id.'/confirm', ['reason' => 'Checked with the bank, one real payment'])->assertOk();
        $this->assertSame('confirmed', DB::table('statement_import_lines')->where('id', $line->id)->value('state'));
    }

    public function test_one_claim_cannot_be_confirmed_on_two_lines(): void
    {
        $this->claim('DUP111', '1500.00');
        $finance = $this->finance();
        $id = $this->importCsv($finance, "Date,TrxID,Details,Amount\n2026-10-05,DUP111,Sent,1500\n2026-10-06,DUP111,Sent,1500\n");
        [$first, $second] = DB::table('statement_import_lines')->where('statement_import_id', $id)->orderBy('id')->pluck('id')->all();
        $imports = app(StatementImports::class);

        $imports->confirm($finance, $first, 'Checked');

        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/operations/accounting/statement-lines/'.$second.'/confirm', ['reason' => 'Checked'])->assertStatus(422);
    }

    public function test_a_claim_already_decided_elsewhere_cannot_be_confirmed(): void
    {
        $claim = $this->claim('ABC123', '1500.00');
        $finance = $this->finance();
        $id = $this->importCsv($finance, "Date,TrxID,Details,Amount\n2026-10-05,ABC123,Sent,1500\n");
        DB::table('payment_claims')->where('id', $claim)->update(['status' => 'rejected']);

        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/operations/accounting/statement-lines/'.$this->line($id, 'ABC123')->id.'/confirm', [])->assertStatus(422);
    }

    public function test_a_line_with_the_wrong_amount_cannot_be_confirmed(): void
    {
        $this->claim('DEF456', '999.00');
        $finance = $this->finance();
        $id = $this->importCsv($finance, "Date,TrxID,Details,Amount\n2026-10-06,DEF456,Sent,1000\n");

        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/operations/accounting/statement-lines/'.$this->line($id, 'DEF456')->id.'/confirm', [])->assertStatus(422);
    }

    public function test_a_suggestion_can_be_turned_down(): void
    {
        $this->claim('ABC123', '1500.00');
        $finance = $this->finance();
        $id = $this->importCsv($finance, "Date,TrxID,Details,Amount\n2026-10-05,ABC123,Sent,1500\n");

        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/accounting/statement-lines/'.$this->line($id, 'ABC123')->id.'/dismiss', [])->assertRedirect();

        $this->assertSame('dismissed', $this->line($id, 'ABC123')->state);
        $this->assertDatabaseHas('audit_events', ['action' => 'statement_import.match_dismissed']);
    }

    public function test_the_same_file_cannot_be_uploaded_twice(): void
    {
        $finance = $this->finance();
        $body = "Date,TrxID,Details,Amount\n2026-10-05,ABC123,Sent,1500\n";
        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/operations/accounting/statements', ['channel' => 'bKash', 'source_reference' => 'First', 'file' => $this->csv($body)])->assertCreated();

        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/operations/accounting/statements', ['channel' => 'bKash', 'source_reference' => 'Again', 'file' => $this->csv($body)])->assertStatus(422);
    }

    public function test_mapping_needs_two_different_columns(): void
    {
        $finance = $this->finance();
        $id = app(StatementImports::class)->upload($finance, 'bKash', 'October statement', $this->csv("Date,TrxID,Details,Amount\n2026-10-05,ABC123,Sent,1500\n"));

        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/operations/accounting/statements/'.$id.'/map', [
            'first_data_row' => 2, 'reference_column' => 1, 'amount_column' => 1,
        ])->assertStatus(422);
    }

    public function test_excel_files_with_formulas_are_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Date', 'TrxID', 'Amount']));
        $writer->addRow(Row::fromValues(['2026-10-05', '=1+1', 100]));
        $writer->close();

        $this->actingAs($this->finance())->onPortal('manage')->postJson('/commands/operations/accounting/statements', [
            'channel' => 'bKash', 'source_reference' => 'October statement',
            'file' => new UploadedFile($path, 'statement.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertStatus(422);
        @unlink($path);
    }

    public function test_only_finance_and_owner_can_use_statement_import(): void
    {
        $reports = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['reports']]]);
        $orders = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['orders']]]);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $payload = ['channel' => 'bKash', 'source_reference' => 'October statement', 'file' => $this->csv("Date,TrxID,Details,Amount\n2026-10-05,ABC123,Sent,1500\n")];

        $this->actingAs($reports)->onPortal('manage')->post('/commands/operations/accounting/statements', $payload)->assertForbidden();
        $this->actingAs($orders)->onPortal('manage')->get('/operations/accounting?tab=import')->assertForbidden();
        $this->assertDatabaseCount('statement_imports', 0);

        $this->actingAs($owner)->onPortal('sadmin')->get('/operations/accounting?tab=import')->assertOk();
    }
}
