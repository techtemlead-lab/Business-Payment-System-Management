<?php

namespace Tests\Feature;

use App\Domain\Notifications\BirthdayGreetings;
use App\Models\User;
use App\Support\DashboardManagement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Owner decision (2026-11-08): birthday SMS is off until the owner or responsible staff turn it on. */
class BirthdaySmsDefaultOffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'Asia/Dhaka'));
    }

    private function runTurnOffMigration(): void
    {
        (require database_path('migrations/2026_11_08_092909_turn_off_birthday_sms_by_default.php'))->up();
    }

    private function birthdayPartner(): User
    {
        return User::factory()->dealer()->create(['phone' => '01751234567', 'profile' => ['date_of_birth' => '1990-11-05']]);
    }

    /** @return list<array<string, mixed>> */
    private function birthdayTexts(): array
    {
        return array_values(array_filter((array) config('madina.sms.sent'), fn (array $text): bool => ($text['event'] ?? null) === BirthdayGreetings::Event));
    }

    private function setSwitch(User $actor, string $portal, bool $isOn): TestResponse
    {
        return $this->actingAs($actor)->onPortal($portal)
            ->post('/commands/dashboards', ['settings' => ['birthday_sms_enabled' => $isOn ? 'true' : 'false']]);
    }

    public function test_the_switch_is_off_on_a_freshly_migrated_database(): void
    {
        $this->assertFalse(DashboardManagement::defaults()['birthday_sms_enabled']);
        $this->assertFalse(DashboardManagement::get()['birthday_sms_enabled']);
    }

    public function test_the_migration_turns_off_a_database_where_it_was_on_and_keeps_other_settings(): void
    {
        DashboardManagement::save(['birthday_sms_enabled' => true, 'dealer_announcement' => 'Keep this notice']);

        $this->runTurnOffMigration();

        $settings = DashboardManagement::get();
        $this->assertFalse($settings['birthday_sms_enabled']);
        $this->assertSame('Keep this notice', $settings['dealer_announcement']);
    }

    public function test_the_migration_is_safe_when_nothing_or_unreadable_data_is_saved(): void
    {
        $this->runTurnOffMigration();
        $this->assertDatabaseMissing('content_pages', ['slug' => 'dashboard_management']);

        DB::table('content_pages')->insert(['slug' => 'dashboard_management', 'title' => 'Dashboard', 'body' => 'not json', 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->runTurnOffMigration();

        $this->assertFalse(DashboardManagement::get()['birthday_sms_enabled']);
    }

    public function test_no_sms_goes_out_while_the_switch_is_off(): void
    {
        $this->birthdayPartner();

        $this->artisan('partners:birthday-sms')
            ->expectsOutputToContain('switched off')
            ->assertSuccessful();

        $this->assertSame([], $this->birthdayTexts());
        $this->assertSame(0, DB::table('partner_birthday_greetings')->count());
    }

    public function test_the_owner_turns_it_on_it_is_audit_logged_and_the_sms_goes_out(): void
    {
        $owner = User::factory()->staff()->create();
        $partner = $this->birthdayPartner();

        $this->setSwitch($owner, 'sadmin', true)->assertSessionHas('success');

        $this->assertTrue(DashboardManagement::get()['birthday_sms_enabled']);
        $event = DB::table('audit_events')->where('action', 'dashboard.birthday_sms_changed')->first();
        $this->assertSame($owner->id, (int) $event->actor_id);
        $metadata = json_decode($event->metadata, true);
        $this->assertFalse($metadata['from']);
        $this->assertTrue($metadata['to']);

        $this->artisan('partners:birthday-sms')->assertSuccessful();
        $this->assertSame([$partner->id], array_column($this->birthdayTexts(), 'user_id'));
    }

    public function test_responsible_staff_can_turn_it_off_again_and_that_is_audit_logged(): void
    {
        DashboardManagement::save(['birthday_sms_enabled' => true]);
        $staff = User::factory()->staff('account_manager')->create();

        $this->setSwitch($staff, 'manage', false)->assertSessionHas('success');

        $this->assertFalse(DashboardManagement::get()['birthday_sms_enabled']);
        $event = DB::table('audit_events')->where('action', 'dashboard.birthday_sms_changed')->first();
        $this->assertSame($staff->id, (int) $event->actor_id);
        $this->assertTrue(json_decode($event->metadata, true)['from']);
    }

    public function test_saving_the_page_without_changing_the_switch_logs_no_birthday_change(): void
    {
        $this->setSwitch(User::factory()->staff()->create(), 'sadmin', false)->assertSessionHas('success');

        $this->assertDatabaseHas('audit_events', ['action' => 'dashboard.updated']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'dashboard.birthday_sms_changed']);
    }

    public function test_people_without_permission_cannot_use_the_switch(): void
    {
        $this->setSwitch(User::factory()->staff('inventory')->create(), 'manage', true)->assertForbidden();

        $this->assertFalse(DashboardManagement::get()['birthday_sms_enabled']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'dashboard.birthday_sms_changed']);
    }

    public function test_the_owner_page_receives_the_switch_as_off(): void
    {
        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/dashboards')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('data.dashboard_settings.birthday_sms_enabled', false)
                ->where('data.dashboard_defaults.birthday_sms_enabled', false));
    }
}
