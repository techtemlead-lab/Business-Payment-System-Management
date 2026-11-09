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
                ->missing('data.payouts_table.rows.data.0.destination'));

        $csv = $this->actingAs($finance)->onPortal('manage')->get(self::ExportUrl.'?month=2026-10')->streamedContent();
        $this->assertStringContainsString($approved, $csv);
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1, 'The file holds the heading and the one approved withdrawal.');
    }

    public function test_the_file_has_full_account_numbers_and_net_amounts(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'D-100', 'name' => 'Rahim Traders']);
        $approved = $this->withdrawal($partner, 'approved', 'bKash', '+8801711000000');
        $this->withdrawal($partner, 'requested');

        $response = $this->actingAs($this->finance())->onPortal('manage')->get(self::ExportUrl.'?month=2026-10');

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

        $csv = $this->actingAs($this->finance())->onPortal('manage')->get(self::ExportUrl.'?month=2026-10&channel=Bank')->streamedContent();

        $this->assertStringContainsString($bank, $csv);
        $this->assertStringNotContainsString($bkash, $csv);
    }

    public function test_later_requests_stay_out_and_earlier_unpaid_ones_stay_in(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $earlier = $this->withdrawal($partner, 'approved', createdAt: '2026-08-15 06:00:00');
        $later = $this->withdrawal($partner, 'approved', createdAt: '2026-11-02 06:00:00');

        $csv = $this->actingAs($this->finance())->onPortal('manage')->get(self::ExportUrl.'?month=2026-10')->streamedContent();

        $this->assertStringContainsString($earlier, $csv);
        $this->assertStringNotContainsString($later, $csv);
    }

    public function test_spreadsheet_formulas_in_partner_text_are_made_safe(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active', 'name' => '=HYPERLINK("http://x")']);
        $this->withdrawal($partner, 'approved', 'Bank', '=cmd|calc');

        $csv = $this->actingAs($this->finance())->onPortal('manage')->get(self::ExportUrl.'?month=2026-10')->streamedContent();

        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString(',=cmd', $csv);
    }

    public function test_exporting_changes_nothing_and_is_logged(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $approved = $this->withdrawal($partner, 'approved');
        $finance = $this->finance();
        $entries = DB::table('journal_entries')->count();

        $this->actingAs($finance)->onPortal('manage')->get(self::ExportUrl.'?month=2026-10')->assertOk();

        $row = DB::table('withdrawals')->where('id', $approved)->first();
        $this->assertSame('approved', $row->status);
        $this->assertNull($row->paid_at);
        $this->assertNull($row->payment_reference);
        $this->assertSame($entries, DB::table('journal_entries')->count());
        $event = DB::table('audit_events')->where('action', 'report.exported')->where('subject', 'payout-batch')->first();
        $this->assertNotNull($event);
        $this->assertSame($finance->id, $event->actor_id);
        $this->assertSame([$approved], json_decode($event->metadata, true)['withdrawal_ids']);
    }

    public function test_export_respects_permissions(): void
    {
        $partner = User::factory()->dealer()->create(['status' => 'active']);
        $this->withdrawal($partner, 'approved');
        $reports = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['reports']]]);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($reports)->onPortal('manage')->get(self::ExportUrl.'?month=2026-10')->assertForbidden();
        $this->actingAs($reports)->onPortal('manage')->get('/operations/accounting?tab=payouts')->assertForbidden();
        $this->actingAs($partner)->onPortal('manage')->get(self::ExportUrl.'?month=2026-10')->assertForbidden();
        $this->actingAs($partner)->onPortal('dealer')->get(self::ExportUrl.'?month=2026-10')->assertNotFound();
        $this->assertDatabaseMissing('audit_events', ['action' => 'report.exported', 'subject' => 'payout-batch']);

        $this->actingAs($owner)->onPortal('sadmin')->get(self::ExportUrl.'?month=2026-10')->assertOk();
    }

    public function test_a_bad_month_or_channel_is_refused(): void
    {
        $finance = $this->finance();

        $this->actingAs($finance)->onPortal('manage')->getJson(self::ExportUrl.'?month=October')->assertStatus(422);
        $this->actingAs($finance)->onPortal('manage')->getJson(self::ExportUrl.'?month=2026-10&channel=Paypal')->assertStatus(422);
    }
}
