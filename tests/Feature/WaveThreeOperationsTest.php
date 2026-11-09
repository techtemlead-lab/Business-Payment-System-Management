<?php

namespace Tests\Feature;

use App\Domain\Finance\ReserveService;
use App\Domain\Security\UserDevices;
use App\Domain\Support\SupportTickets;
use App\Domain\Support\UserManual;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Partner replies on support requests, the new-device notice, the one path for marketing
 * money, and the manual text for the single-owner rules and the newer features.
 */
class WaveThreeOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const NewChapters = ['contests_guide', 'partner_scorecard', 'duplicate_account_check', 'device_list', 'saved_filters', 'batches_on_hold'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_partner_reply_moves_a_resolved_request_back_and_tells_the_office(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $supportStaff = User::factory()->staff('support')->create(['status' => 'active']);
        $accountsStaff = User::factory()->staff('account_manager')->create(['status' => 'active']);
        $unrelatedStaff = User::factory()->staff('inventory')->create(['status' => 'active']);
        $ticket = app(SupportTickets::class)->create($dealer, ['category' => 'technical', 'subject' => 'App problem', 'body' => 'It stopped.']);
        app(SupportTickets::class)->reply($supportStaff, $ticket['id'], 'Please try again.', 'resolved');
        $this->assertDatabaseHas('support_tickets', ['id' => $ticket['id'], 'status' => 'resolved']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/support-tickets/'.$ticket['id'].'/reply', ['body' => 'Still not working.', 'status' => 'resolved'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('support_tickets', ['id' => $ticket['id'], 'status' => 'pending', 'resolved_at' => null]);
        $this->assertDatabaseHas('support_ticket_replies', ['support_ticket_id' => $ticket['id'], 'user_id' => $dealer->id, 'is_staff' => false, 'body' => 'Still not working.']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $supportStaff->id, 'title' => __('wave3_ops.support_reply_notice_title')]);
        $this->assertDatabaseHas('account_notices', ['user_id' => $accountsStaff->id, 'title' => __('wave3_ops.support_reply_notice_title')]);
        $this->assertDatabaseMissing('account_notices', ['user_id' => $unrelatedStaff->id, 'title' => __('wave3_ops.support_reply_notice_title')]);
        $this->assertSame([$ticket['id']], array_column(app(SupportTickets::class)->adminIndex('pending')['tickets'], 'id'));
    }

    public function test_a_partner_cannot_reply_on_someone_elses_request(): void
    {
        $owner = User::factory()->dealer()->create(['status' => 'active']);
        $other = User::factory()->dealer()->create(['status' => 'active']);
        $ticket = app(SupportTickets::class)->create($owner, ['category' => 'technical', 'subject' => 'Mine', 'body' => 'Private.']);

        $this->actingAs($other)->onPortal('dealer')->post('/commands/support-tickets/'.$ticket['id'].'/reply', ['body' => 'Hello'])->assertNotFound();

        $this->assertDatabaseCount('support_ticket_replies', 0);
    }

    public function test_a_staff_reply_still_does_not_notify_the_office(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $supportStaff = User::factory()->staff('support')->create(['status' => 'active']);
        $ticket = app(SupportTickets::class)->create($dealer, ['category' => 'technical', 'subject' => 'App', 'body' => 'Broken.']);

        app(SupportTickets::class)->reply($supportStaff, $ticket['id'], 'Looking.');

        $this->assertDatabaseHas('support_tickets', ['id' => $ticket['id'], 'status' => 'in_progress']);
        $this->assertDatabaseMissing('account_notices', ['title' => __('wave3_ops.support_reply_notice_title')]);
    }

    public function test_staff_get_a_notice_only_for_a_browser_and_system_not_seen_before(): void
    {
        $staff = User::factory()->staff('inventory')->create(['status' => 'active']);
        $title = __('wave3_ops.new_device_title', [], $staff->preferredLocale());

        $this->signIn($staff, 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36');
        $this->assertDatabaseMissing('account_notices', ['user_id' => $staff->id, 'title' => $title]);

        $this->signIn($staff, 'Mozilla/5.0 (Windows NT 10.0) Chrome/121.0 Safari/537.36');
        $this->assertDatabaseMissing('account_notices', ['user_id' => $staff->id, 'title' => $title]);

        $this->signIn($staff, 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0 Mobile Safari/537.36');
        $this->assertDatabaseHas('account_notices', ['user_id' => $staff->id, 'title' => $title]);
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $staff->id)->where('title', $title)->count());
    }

    public function test_partners_get_no_new_device_notice(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->signIn($dealer, 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36');
        $this->signIn($dealer, 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0 Mobile Safari/537.36');

        $this->assertDatabaseCount('known_sign_in_devices', 0);
    }

    public function test_a_broken_device_check_never_stops_a_sign_in(): void
    {
        $staff = User::factory()->staff('inventory')->create(['status' => 'active']);
        $this->app->bind(UserDevices::class, fn () => throw new \RuntimeException('boom'));

        $this->signIn($staff, 'Mozilla/5.0 Chrome/120.0');

        $this->assertTrue(Auth::check());
    }

    public function test_marketing_money_can_no_longer_be_requested_on_the_reserve_payouts_page(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        $this->expectException(ValidationException::class);

        app(ReserveService::class)->request($finance, ['program' => 'marketing', 'user_id' => null, 'payee_name' => 'Print shop', 'amount' => '100.00', 'request_key' => (string) Str::uuid(), 'reason' => 'Leaflets']);
    }

    public function test_older_marketing_payouts_stay_listed_and_a_waiting_one_can_still_be_cancelled(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);
        $legacyId = DB::table('reserve_disbursements')->insertGetId([
            'program' => 'marketing', 'payee_name' => 'Old Printer', 'amount' => '50.00', 'request_key' => (string) Str::uuid(), 'reason' => 'Older marketing cost',
            'status' => 'requested', 'created_by' => $finance->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($finance)->onPortal('manage')->get('/operations/reserve-payouts')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tables.0.key', 'reserve_requests')
                ->where('tables.0.page.data', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => (int) $row['id'] === $legacyId)));

        app(ReserveService::class)->cancel($finance, $legacyId, 'No longer needed');

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $legacyId, 'status' => 'cancelled']);
    }

    public function test_the_four_lines_follow_the_single_owner_rules_in_both_languages(): void
    {
        foreach (['payouts_settlements_t1', 'withdrawals_s5', 'dual_dashboard_s5', 'staff_fulfillment_w1'] as $key) {
            $english = (string) __('manual.'.$key, [], 'en');
            $bangla = (string) __('manual.'.$key, [], 'bn');
            $this->assertNotSame('manual.'.$key, $english);
            $this->assertNotSame('manual.'.$key, $bangla);
            $this->assertNotSame($english, $bangla);
            $this->assertStringNotContainsStringIgnoringCase('second person', $english, $key);
        }
        foreach (['payouts_settlements_t1', 'withdrawals_s5'] as $key) {
            $this->assertStringContainsString('owner', __('manual.'.$key, [], 'en'));
            $this->assertStringContainsString('মালিক', __('manual.'.$key, [], 'bn'));
        }
    }

    public function test_the_new_chapters_exist_in_both_languages_for_the_right_people(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        app()->setLocale('en');
        $english = collect(app(UserManual::class)->chaptersFor($owner))->keyBy('key');
        app()->setLocale('bn');
        $bangla = collect(app(UserManual::class)->chaptersFor($owner))->keyBy('key');

        foreach (self::NewChapters as $key) {
            $this->assertTrue($english->has($key), "$key is not registered");
            foreach (['badge', 'title', 'summary', 'purpose', 'why'] as $field) {
                $this->assertNotEmpty($bangla[$key][$field], "$key bn $field");
                $this->assertNotSame($english[$key][$field], $bangla[$key][$field], "$key $field is not translated");
            }
            foreach (['steps', 'outcomes', 'tips', 'warnings'] as $list) {
                $this->assertNotEmpty($english[$key][$list], "$key en $list");
                $this->assertCount(count($english[$key][$list]), $bangla[$key][$list], "$key $list differ between languages");
            }
        }

        $dealerKeys = array_column(app(UserManual::class)->chaptersFor(User::factory()->dealer()->create(['status' => 'active'])), 'key');
        $this->assertContains('contests_guide', $dealerKeys);
        $this->assertContains('device_list', $dealerKeys);
        $this->assertNotContains('duplicate_account_check', $dealerKeys);
        $this->assertNotContains('batches_on_hold', $dealerKeys);
    }

    /**
     * Fires the framework's real sign-in event, the one the login code sends, without going
     * through the password or captcha screens.
     */
    private function signIn(User $user, string $userAgent): void
    {
        $this->app->instance('request', Request::create('/login', 'POST', server: ['HTTP_USER_AGENT' => $userAgent]));
        Auth::login($user);
    }
}
