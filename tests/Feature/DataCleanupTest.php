<?php

namespace Tests\Feature;

use App\Domain\Integrations\SmsGatewayClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owner decision: nothing is deleted automatically; the owner deletes old login codes,
 * SMS records and closed enquiries by hand, one by one or in bulk.
 */
class DataCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function enquiry(string $status, int $daysAgo): int
    {
        return DB::table('enquiries')->insertGetId([
            'name' => 'Visitor '.$status.$daysAgo, 'phone' => '01711000000', 'topic' => 'products', 'message' => 'Hello',
            'status' => $status, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    private function smsLog(int $daysAgo): int
    {
        return DB::table('sms_logs')->insertGetId([
            'phone_masked' => '••••1234', 'provider' => 'bulk_sms_bd', 'sent' => true, 'length' => 40, 'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function resetCode(string $email, int $daysAgo): void
    {
        DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'hashed', 'created_at' => now()->subDays($daysAgo)]);
    }

    public function test_only_owners_open_the_page_and_delete(): void
    {
        $closed = $this->enquiry('closed', 90);
        $contentStaff = User::factory()->staff('employee')->create(['profile' => ['permissions' => config('madina.roles.super_admin')]]);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($contentStaff)->onPortal('manage')->get('/data-cleanup')->assertNotFound();
        $this->actingAs($contentStaff)->onPortal('sadmin')->get('/data-cleanup')->assertForbidden();
        $this->actingAs($contentStaff)->onPortal('manage')->post('/commands/data-cleanup/delete', ['refs' => ['enq:'.$closed], 'confirm' => true])->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/data-cleanup/delete', ['refs' => ['enq:'.$closed], 'confirm' => true])->assertForbidden();
        $this->assertDatabaseHas('enquiries', ['id' => $closed]);

        foreach (['super_admin', 'md', 'chairman'] as $role) {
            $this->actingAs(User::factory()->staff($role)->create())->onPortal('sadmin')->get('/data-cleanup')->assertOk();
        }
    }

    public function test_the_page_lists_only_the_three_kinds_with_age_and_kind_filters(): void
    {
        $owner = User::factory()->staff('md')->create();
        $oldClosed = $this->enquiry('closed', 90);
        $this->enquiry('closed', 5);
        $this->enquiry('new', 90);
        $oldSms = $this->smsLog(60);
        $this->smsLog(1);
        $this->resetCode('old@example.test', 45);

        $this->actingAs($owner)->onPortal('sadmin')->get('/data-cleanup?older_than_days=30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'data-cleanup')
                ->where('data.data_cleanup.counts', ['login_codes' => 1, 'sms_logs' => 1, 'closed_enquiries' => 1])
                ->where('data.data_cleanup.rows.total', 3)
                ->where('data.data_cleanup.rows.data.0.ref', 'enq:'.$oldClosed)
                ->where('data.data_cleanup.rows.data.1.ref', 'sms:'.$oldSms)
                ->where('data.data_cleanup.rows.data.2.ref', 'reset:old@example.test'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/data-cleanup?older_than_days=0&kind=closed_enquiries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.data_cleanup.rows.total', 2)
                ->where('data.data_cleanup.filters.kind', 'closed_enquiries'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/data-cleanup?kind=orders')->assertSessionHasErrors('kind');
    }

    public function test_bulk_delete_removes_only_chosen_safe_rows_and_records_counts(): void
    {
        $owner = User::factory()->staff('md')->create();
        $closed = $this->enquiry('closed', 90);
        $open = $this->enquiry('in_progress', 90);
        $keptClosed = $this->enquiry('closed', 90);
        $sms = $this->smsLog(60);
        $this->resetCode('old@example.test', 45);
        $ordersBefore = DB::table('orders')->count();
        $auditBefore = DB::table('audit_events')->count();

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/data-cleanup/delete', ['refs' => ['enq:'.$closed, 'sms:'.$sms], 'confirm' => false])
            ->assertSessionHasErrors('confirm');
        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/data-cleanup/delete', ['refs' => ['orders:1'], 'confirm' => true])
            ->assertSessionHasErrors('refs');

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/data-cleanup/delete', ['refs' => ['enq:'.$closed, 'enq:'.$open, 'sms:'.$sms, 'reset:old@example.test'], 'confirm' => true])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('enquiries', ['id' => $closed]);
        $this->assertDatabaseHas('enquiries', ['id' => $open]);
        $this->assertDatabaseHas('enquiries', ['id' => $keptClosed]);
        $this->assertDatabaseMissing('sms_logs', ['id' => $sms]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'old@example.test']);
        $this->assertSame($ordersBefore, DB::table('orders')->count());

        $audit = DB::table('audit_events')->where('action', 'data_cleanup.deleted')->sole();
        $this->assertSame($owner->id, (int) $audit->actor_id);
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertSame(['login_codes' => 1, 'sms_logs' => 1, 'closed_enquiries' => 1], $metadata['counts']);
        $this->assertSame(3, $metadata['total']);
        $this->assertSame(4, $metadata['requested']);
        $this->assertSame($auditBefore + 1, DB::table('audit_events')->count(), 'Audit records are never deleted.');
    }

    public function test_only_expired_sms_login_codes_are_listed_and_deleted_when_the_cache_is_in_the_database(): void
    {
        config(['cache.default' => 'database', 'cache.stores.database.driver' => 'database', 'cache.stores.database.table' => 'cache']);
        $prefix = (string) config('cache.prefix');
        DB::table('cache')->insert([
            ['key' => $prefix.'login_otp:dealer:7', 'value' => 'x', 'expiration' => now()->subDays(40)->getTimestamp()],
            ['key' => $prefix.'login_otp:dealer:8', 'value' => 'x', 'expiration' => now()->addMinutes(5)->getTimestamp()],
            ['key' => $prefix.'some_other_cache', 'value' => 'x', 'expiration' => now()->subDays(40)->getTimestamp()],
        ]);
        $owner = User::factory()->staff('md')->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/data-cleanup?older_than_days=30&kind=login_codes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.data_cleanup.rows.total', 1)
                ->where('data.data_cleanup.rows.data.0.ref', 'otp:'.$prefix.'login_otp:dealer:7')
                ->where('data.data_cleanup.rows.data.0.label', 'login_otp:dealer:7'));

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/data-cleanup/delete', [
            'refs' => ['otp:'.$prefix.'login_otp:dealer:7', 'otp:'.$prefix.'login_otp:dealer:8', 'otp:'.$prefix.'some_other_cache'],
            'confirm' => true,
        ])->assertRedirect();

        $this->assertDatabaseMissing('cache', ['key' => $prefix.'login_otp:dealer:7']);
        $this->assertDatabaseHas('cache', ['key' => $prefix.'login_otp:dealer:8']);
        $this->assertDatabaseHas('cache', ['key' => $prefix.'some_other_cache']);
    }

    public function test_sms_sends_are_recorded_without_the_message_text(): void
    {
        Http::fake();

        app(SmsGatewayClient::class)->send('+8801711223344', 'আপনার লগইন কোড 123456');

        $row = DB::table('sms_logs')->sole();
        $this->assertSame('••••3344', $row->phone_masked);
        $this->assertFalse((bool) $row->sent, 'No gateway is switched on in tests.');
        $this->assertStringNotContainsString('123456', json_encode($row));
    }
}
