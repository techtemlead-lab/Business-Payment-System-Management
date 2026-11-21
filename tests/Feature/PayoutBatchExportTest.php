<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PayoutBatchExportTest extends TestCase
{
    use RefreshDatabase;

    private const ExportUrl = '/commands/operations/accounting/payout-batch/export';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function withdrawal(User $partner, string $status, string $channel = 'bKash', string $destination = '01711000000', string $createdAt = '2026-10-15 06:00:00'): string
    {
        $id = (string) Str::uuid();
        DB::table('withdrawals')->insert([
            'id' => $id, 'user_id' => $partner->id, 'amount' => '1000.00', 'withheld_amount' => '50.00', 'net_amount' => '950.00',
            'minimum_snapshot' => '500.00', 'status' => $status, 'channel' => $channel, 'destination' => encrypt($destination),
            'idempotency_key' => (string) Str::uuid(), 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $id;
    }

    private function finance(): User
    {
        return User::factory()->staff('finance')->create(['status' => 'active']);
    }

    /** @return array<string, string> */
    private function exportData(string $month, ?string $channel = null, ?string $batchId = null): array
    {
        return ['batch_id' => $batchId ?? (string) Str::uuid(), 'month' => $month, ...($channel !== null ? ['channel' => $channel] : [])];
    }

    public function test_only_approved_withdrawals_are_listed(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'D-100']);
        $approved = $this->withdrawal($partner, 'approved');
        $this->withdrawal($partner, 'requested');
        $this->withdrawal($partner, 'paid');
        $this->withdrawal($partner, 'rejected');

        $finance = $this->finance();
        $this->actingAs($finance)->onPortal('manage')->get('/operations/accounting?tab=payouts&month=2026-10')->assertRedirect('/payouts-hub');

        $this->actingAs($finance)->onPortal('manage')->get('/payouts-hub?status=approved')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/manage/Dashboard')
                ->has('data.payouts_table.rows.data', 1)
                ->where('data.payouts_table.rows.data.0.id', $approved)
                ->where('data.payouts_table.rows.data.0.net_amount', '950.00')
                ->where('data.payouts_table.rows.data.0.destination_masked', '•••••••0000')
                ->where('data.payouts_table.rows.data.0.in_bank_file', false)
                ->missing('data.payouts_table.rows.data.0.destination'));

        $csv = $this->actingAs($finance)->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10'))->streamedContent();
        $this->assertStringContainsString($approved, $csv);
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1, 'The file holds the heading and the one approved withdrawal.');
    }

    public function test_the_file_has_full_account_numbers_and_net_amounts(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'D-100', 'name' => 'Rahim Traders']);
        $approved = $this->withdrawal($partner, 'approved', 'bKash', '+8801711000000');
        $this->withdrawal($partner, 'requested');

        $response = $this->actingAs($this->finance())->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('serial,withdrawal_id,dealer_code,payee_name,channel,destination,requested_amount,tax_withheld,net_to_pay,requested_on', $csv);
        $this->assertStringContainsString($approved, $csv);
        $this->assertStringContainsString('8801711000000', $csv);
        $this->assertStringNotContainsString("'+880", $csv);
        $this->assertStringContainsString(',1000.00,50.00,950.00,', $csv);
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1);
    }

    public function test_the_file_can_be_limited_to_one_channel(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $bank = $this->withdrawal($partner, 'approved', 'Bank', '1234567890');
        $bkash = $this->withdrawal($partner, 'approved', 'bKash');

        $csv = $this->actingAs($this->finance())->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10', 'Bank'))->streamedContent();

        $this->assertStringContainsString($bank, $csv);
        $this->assertStringNotContainsString($bkash, $csv);
        $this->assertNull(DB::table('withdrawals')->where('id', $bkash)->value('exported_at'), 'A row the file did not list stays outside any batch.');
    }

    public function test_later_requests_stay_out_and_earlier_unpaid_ones_stay_in(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $earlier = $this->withdrawal($partner, 'approved', createdAt: '2026-08-15 06:00:00');
        $later = $this->withdrawal($partner, 'approved', createdAt: '2026-11-02 06:00:00');

        $csv = $this->actingAs($this->finance())->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10'))->streamedContent();

        $this->assertStringContainsString($earlier, $csv);
        $this->assertStringNotContainsString($later, $csv);
    }

    public function test_spreadsheet_formulas_in_partner_text_are_made_safe(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active', 'name' => '=HYPERLINK("http://x")']);
        $this->withdrawal($partner, 'approved', 'Bank', '=cmd|calc');

        $csv = $this->actingAs($this->finance())->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10'))->streamedContent();

        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString(',=cmd', $csv);
    }

    public function test_exporting_marks_the_rows_in_a_bank_file_moves_no_money_and_is_logged(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $approved = $this->withdrawal($partner, 'approved');
        $finance = $this->finance();
        $entries = DB::table('journal_entries')->count();
        $batchId = (string) Str::uuid();

        $this->actingAs($finance)->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10', batchId: $batchId))->assertOk();

        $row = DB::table('withdrawals')->where('id', $approved)->first();
        $this->assertSame('approved', $row->status);
        $this->assertNull($row->paid_at);
        $this->assertNull($row->payment_reference);
        $this->assertSame($batchId, $row->payout_batch_id);
        $this->assertNotNull($row->exported_at);
        $this->assertSame($entries, DB::table('journal_entries')->count());
        $this->assertDatabaseHas('payout_batches', ['id' => $batchId, 'month' => '2026-10', 'channel' => null, 'row_count' => 1, 'total_net' => '950.00', 'created_by' => $finance->id]);
        $this->assertDatabaseHas('withdrawal_events', ['withdrawal_id' => $approved, 'event' => 'exported', 'actor_id' => $finance->id]);
        $event = DB::table('audit_events')->where('action', 'report.exported')->where('subject', 'payout-batch')->first();
        $this->assertNotNull($event);
        $this->assertSame($finance->id, $event->actor_id);
        $metadata = json_decode($event->metadata, true);
        $this->assertSame([$approved], $metadata['withdrawal_ids']);
        $this->assertSame($batchId, $metadata['batch_id']);
    }

    public function test_a_second_file_leaves_out_withdrawals_already_in_a_bank_file(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $first = $this->withdrawal($partner, 'approved');
        $finance = $this->finance();
        $this->actingAs($finance)->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10'))->assertOk();
        $second = $this->withdrawal($partner, 'approved', createdAt: '2026-10-20 06:00:00');

        $csv = $this->actingAs($finance)->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-11'))->streamedContent();

        $this->assertStringContainsString($second, $csv);
        $this->assertStringNotContainsString($first, $csv, 'A later month\'s file must not list a withdrawal already sent to the bank.');
        $this->assertSame(2, DB::table('payout_batches')->count());

        $empty = $this->actingAs($finance)->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-11'))->streamedContent();
        $this->assertSame(1, substr_count(trim($empty), "\n") + 1, 'Nothing is left to send: the file holds only the heading.');
        $this->assertSame(2, DB::table('payout_batches')->count(), 'An empty file makes no batch.');
    }

    public function test_pressing_the_button_twice_gives_the_same_file_and_no_second_batch(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $approved = $this->withdrawal($partner, 'approved');
        $finance = $this->finance();
        $data = $this->exportData('2026-10');
        $first = $this->actingAs($finance)->onPortal('manage')->post(self::ExportUrl, $data)->streamedContent();
        $exportedAt = DB::table('withdrawals')->where('id', $approved)->value('exported_at');
        $this->withdrawal($partner, 'approved');

        $again = $this->actingAs($finance)->onPortal('manage')->post(self::ExportUrl, $data)->streamedContent();

        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('payout_batches')->count());
        $this->assertSame($exportedAt, DB::table('withdrawals')->where('id', $approved)->value('exported_at'));
        $this->assertSame(1, DB::table('withdrawal_events')->where('withdrawal_id', $approved)->where('event', 'exported')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'payout_batch.downloaded_again', 'subject' => $data['batch_id']]);

        $this->actingAs($finance)->onPortal('manage')->postJson(self::ExportUrl, [...$data, 'month' => '2026-09'])->assertStatus(409);
    }

    public function test_an_earlier_file_can_be_downloaded_again_on_purpose_without_paid_rows(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $stillWaiting = $this->withdrawal($partner, 'approved');
        $paidSince = $this->withdrawal($partner, 'approved');
        $finance = $this->finance();
        $batchId = (string) Str::uuid();
        $this->actingAs($finance)->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10', batchId: $batchId))->assertOk();
        DB::table('withdrawals')->where('id', $paidSince)->update(['status' => 'paid', 'paid_at' => now()]);

        $response = $this->actingAs($finance)->onPortal('manage')->get('/commands/operations/accounting/payout-batches/'.$batchId.'/file');

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString($stillWaiting, $csv);
        $this->assertStringNotContainsString($paidSince, $csv);
        $event = DB::table('audit_events')->where('action', 'payout_batch.downloaded_again')->where('subject', $batchId)->first();
        $this->assertNotNull($event);
        $this->assertSame([$stillWaiting], json_decode($event->metadata, true)['withdrawal_ids']);
        $this->actingAs($finance)->onPortal('manage')->get('/commands/operations/accounting/payout-batches/'.Str::uuid().'/file')->assertNotFound();
    }

    public function test_withdrawals_on_hold_are_not_put_in_a_file(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $held = $this->withdrawal($partner, 'approved');
        DB::table('withdrawals')->where('id', $held)->update(['on_hold_at' => now()]);

        $csv = $this->actingAs($this->finance())->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10'))->streamedContent();

        $this->assertStringNotContainsString($held, $csv);
        $this->assertNull(DB::table('withdrawals')->where('id', $held)->value('exported_at'));
    }

    public function test_export_respects_permissions(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $this->withdrawal($partner, 'approved');
        $reports = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['reports']]]);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($reports)->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10'))->assertForbidden();
        $this->actingAs($reports)->onPortal('manage')->get('/operations/accounting?tab=payouts')->assertForbidden();
        $this->actingAs($partner)->onPortal('manage')->post(self::ExportUrl, $this->exportData('2026-10'))->assertForbidden();
        $this->actingAs($partner)->onPortal('dealer')->post(self::ExportUrl, $this->exportData('2026-10'))->assertNotFound();
        $this->assertDatabaseMissing('audit_events', ['action' => 'report.exported', 'subject' => 'payout-batch']);
        $this->assertSame(0, DB::table('payout_batches')->count());

        $this->actingAs($owner)->onPortal('sadmin')->post(self::ExportUrl, $this->exportData('2026-10'))->assertOk();
    }

    public function test_a_bad_month_channel_or_batch_id_is_refused(): void
    {
        $finance = $this->finance();

        $this->actingAs($finance)->onPortal('manage')->postJson(self::ExportUrl, $this->exportData('October'))->assertStatus(422);
        $this->actingAs($finance)->onPortal('manage')->postJson(self::ExportUrl, $this->exportData('2026-10', 'Paypal'))->assertStatus(422);
        $this->actingAs($finance)->onPortal('manage')->postJson(self::ExportUrl, ['month' => '2026-10'])->assertStatus(422);
        $this->actingAs($finance)->onPortal('manage')->get(self::ExportUrl.'?month=2026-10')->assertStatus(405);
    }
}
