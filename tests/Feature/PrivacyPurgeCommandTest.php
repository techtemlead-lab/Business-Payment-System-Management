<?php

namespace Tests\Feature;

use App\Console\Commands\PurgeOldPersonalData;
use App\Models\Order;
use App\Models\SiteFeedback;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivacyPurgeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('madina.privacy.closed_record_retention_days', 365);
    }

    public function test_dry_run_is_the_default_and_changes_nothing(): void
    {
        $this->seedRecords();
        $before = $this->snapshot();

        $this->artisan('privacy:purge')
            ->expectsOutputToContain('Dry run: nothing was changed')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseMissing('audit_events', ['action' => 'privacy.purged']);
    }

    public function test_force_cleans_only_old_closed_records(): void
    {
        $ids = $this->seedRecords();

        $this->artisan('privacy:purge --force')->assertSuccessful();

        $oldEnquiry = DB::table('enquiries')->find($ids['old_closed_enquiry']);
        $this->assertSame(PurgeOldPersonalData::RemovedName, $oldEnquiry->name);
        $this->assertSame('', $oldEnquiry->phone);
        $this->assertSame('', $oldEnquiry->message);
        $this->assertSame('partnership_enquiry', $oldEnquiry->topic);
        $this->assertSame('01720000301', DB::table('enquiries')->where('id', $ids['recent_closed_enquiry'])->value('phone'));
        $this->assertSame('01720000302', DB::table('enquiries')->where('id', $ids['old_open_enquiry'])->value('phone'));

        $application = DB::table('dealer_applications')->where('id', $ids['old_rejected_application'])->first();
        $this->assertSame('removed-'.$ids['old_rejected_application'].'@privacy.invalid', $application->email);
        $this->assertSame(['role' => 'sub_dealer', 'privacy_removed' => true], json_decode($application->data, true));
        $this->assertSame('pending@example.test', DB::table('dealer_applications')->where('id', $ids['old_pending_application'])->value('email'));

        $this->assertSame(PurgeOldPersonalData::RemovedName, SiteFeedback::find($ids['old_rejected_feedback'])->name);
        $this->assertNotSame(PurgeOldPersonalData::RemovedName, SiteFeedback::find($ids['old_approved_feedback'])->name);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'old@example.test']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'new@example.test']);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'new-session']);

        $this->assertDatabaseHas('audit_events', ['action' => 'privacy.purged', 'subject' => 'retention']);
    }

    public function test_orders_and_audit_records_are_never_touched(): void
    {
        $ids = $this->seedRecords();
        $order = DB::table('orders')->where('id', $ids['old_order'])->first();
        $audit = DB::table('audit_events')->where('id', $ids['old_audit'])->first();

        $this->artisan('privacy:purge --force')->assertSuccessful();

        $this->assertEquals($order, DB::table('orders')->where('id', $ids['old_order'])->first());
        $this->assertEquals($audit, DB::table('audit_events')->where('id', $ids['old_audit'])->first());
    }

    public function test_running_twice_finds_nothing_more_to_clean(): void
    {
        $this->seedRecords();
        $this->artisan('privacy:purge --force')->assertSuccessful();
        $after = $this->snapshot();

        $this->artisan('privacy:purge --force')->expectsOutputToContain('Done. 0 record(s) cleaned')->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_refuses_to_run_without_a_retention_period(): void
    {
        Config::set('madina.privacy.closed_record_retention_days', 0);

        $this->artisan('privacy:purge --force')->assertFailed();
    }

    public function test_purge_is_never_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn (Event $event): string => (string) $event->command);

        $this->assertFalse($commands->contains(fn (string $command): bool => str_contains($command, 'privacy:purge')));
    }

    /** @return array<string, int|string> */
    private function seedRecords(): array
    {
        $old = now()->subDays(400);
        $recent = now()->subDays(10);
        $staff = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();

        $enquiry = fn (string $status, string $phone, $at): int => DB::table('enquiries')->insertGetId([
            'name' => 'Rahim Uddin', 'phone' => $phone, 'topic' => 'partnership_enquiry', 'message' => 'Please call me about a dealership.',
            'status' => $status, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $application = function (string $status, string $email, $at) use ($staff): string {
            $id = (string) Str::uuid();
            DB::table('dealer_applications')->insert(['id' => $id, 'submitted_by' => $staff->id, 'email' => $email,
                'data' => json_encode(['name' => 'Karim', 'phone' => '01720000399', 'role' => 'sub_dealer']), 'status' => $status,
                'created_at' => $at, 'updated_at' => $at]);

            return $id;
        };
        $feedback = function (string $status, $at): int {
            $row = SiteFeedback::factory()->create(['status' => $status]);
            DB::table('site_feedback')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);

            return $row->id;
        };
        DB::table('password_reset_tokens')->insert([
            ['email' => 'old@example.test', 'token' => 'x', 'created_at' => $old],
            ['email' => 'new@example.test', 'token' => 'y', 'created_at' => now()],
        ]);
        DB::table('sessions')->insert([
            ['id' => 'old-session', 'user_id' => $dealer->id, 'ip_address' => '203.0.113.5', 'user_agent' => 'Old browser', 'payload' => '', 'last_activity' => $old->getTimestamp()],
            ['id' => 'new-session', 'user_id' => $dealer->id, 'ip_address' => '203.0.113.6', 'user_agent' => 'New browser', 'payload' => '', 'last_activity' => now()->getTimestamp()],
        ]);
        $order = Order::create(['number' => 'ORD-PRIV0001', 'user_id' => $dealer->id, 'status' => 'delivered', 'total' => '500.00', 'paid' => '500.00', 'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random())]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $old, 'updated_at' => $old]);
        $audit = DB::table('audit_events')->insertGetId(['actor_id' => $staff->id, 'action' => 'account.updated', 'subject' => (string) $dealer->id,
            'metadata' => json_encode(['phone' => '01720000399']), 'created_at' => $old]);

        return [
            'old_closed_enquiry' => $enquiry('closed', '01720000300', $old),
            'recent_closed_enquiry' => $enquiry('closed', '01720000301', $recent),
            'old_open_enquiry' => $enquiry('new', '01720000302', $old),
            'old_rejected_application' => $application('rejected', 'rejected@example.test', $old),
            'old_pending_application' => $application('pending', 'pending@example.test', $old),
            'old_rejected_feedback' => $feedback('rejected', $old),
            'old_approved_feedback' => $feedback('approved', $old),
            'old_order' => $order->id,
            'old_audit' => $audit,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = [];
        foreach (['enquiries', 'dealer_applications', 'site_feedback', 'password_reset_tokens', 'sessions', 'orders'] as $table) {
            $rows[$table] = DB::table($table)->get()->map(fn (object $row): array => (array) $row)->sortBy(fn (array $row): string => json_encode($row))->values()->all();
        }

        return $rows;
    }
}
