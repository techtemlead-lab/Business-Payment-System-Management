<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Notifications\BirthdayGreetings;
use App\Models\User;
use App\Support\DashboardManagement;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartnerBirthdaySmsTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
    }

    public function test_switch_is_on_by_default_and_sends_without_saving_settings(): void
    {
        $this->assertTrue(DashboardManagement::defaults()['birthday_sms_enabled']);
        $this->assertTrue(DashboardManagement::get()['birthday_sms_enabled']);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'Asia/Dhaka'));
        $dealer = $this->partner('dealer', '1990-11-05');

        $this->artisan('partners:birthday-sms')->assertSuccessful();

        $this->assertSame([$dealer->id], array_column($this->birthdayTexts(), 'user_id'));
    }

    public function test_nothing_is_sent_when_the_owner_turns_the_switch_off(): void
    {
        DashboardManagement::save(['birthday_sms_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'Asia/Dhaka'));
        $this->partner('dealer', '1990-11-05');

        $this->artisan('partners:birthday-sms')
            ->expectsOutputToContain('switched off')
            ->assertSuccessful();

        $this->assertSame([], $this->birthdayTexts());
        $this->assertSame(0, DB::table('partner_birthday_greetings')->count());
    }

    public function test_nothing_is_sent_when_no_sms_provider_is_set_up(): void
    {
        Config::set('madina.sms.driver', 'log');
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'Asia/Dhaka'));
        $this->partner('dealer', '1990-11-05');

        $this->artisan('partners:birthday-sms')
            ->expectsOutputToContain('SMS is not set up')
            ->assertSuccessful();

        $this->assertSame(
            ['enabled' => false, 'sent' => 0, 'already_sent' => 0, 'failed' => 0, 'sms_ready' => false],
            app(BirthdayGreetings::class)->sendForToday(),
        );
        $this->assertSame(0, DB::table('partner_birthday_greetings')->count());
    }

    public function test_nothing_is_sent_in_preview_mode(): void
    {
        Config::set('madina.preview_mode', true);
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'Asia/Dhaka'));
        $this->partner('dealer', '1990-11-05');

        $this->artisan('partners:birthday-sms')
            ->expectsOutputToContain('SMS is not set up')
            ->assertSuccessful();

        $this->assertSame([], $this->birthdayTexts());
        $this->assertSame(0, DB::table('partner_birthday_greetings')->count());
    }

    public function test_active_dealers_and_sub_dealers_born_today_get_one_sms(): void
    {
        $this->turnOn();
        // 5 November in Dhaka, although it is still 4 November in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 00:30:00', 'Asia/Dhaka'));
        $dealer = $this->partner('dealer', '1990-11-05', phone: '01711000001');
        $subDealer = $this->partner('sub_dealer', '1988-11-05', phone: '01811000002');
        $this->partner('dealer', '1990-11-04', phone: '01711000003');
        $this->partner('dealer', '1990-11-05', phone: '01711000004', status: 'suspended');
        $staff = User::factory()->staff('employee')->create(['phone' => '01911000005', 'profile' => ['date_of_birth' => '1990-11-05']]);
        $this->partner('dealer', null, phone: '01711000006');

        $this->artisan('partners:birthday-sms')->assertSuccessful();

        $texts = $this->birthdayTexts();
        $this->assertEqualsCanonicalizing([$dealer->id, $subDealer->id], array_column($texts, 'user_id'));
        $this->assertNotContains($staff->id, array_column($texts, 'user_id'));
        $this->assertSame(
            'মদিনা পরিবারের পক্ষ থেকে শুভ জন্মদিন, '.$dealer->name.'! আপনার দিনটি ভালো কাটুক।',
            collect($texts)->firstWhere('user_id', $dealer->id)['message'],
        );
        $this->assertDatabaseHas('partner_birthday_greetings', ['user_id' => $dealer->id, 'year' => 2026]);
    }

    public function test_a_partner_is_never_greeted_twice_in_the_same_year(): void
    {
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'Asia/Dhaka'));
        $dealer = $this->partner('dealer', '1990-11-05');

        $this->artisan('partners:birthday-sms')->assertSuccessful();
        $this->artisan('partners:birthday-sms')->assertSuccessful();
        $second = app(BirthdayGreetings::class)->sendForToday();

        $this->assertCount(1, $this->birthdayTexts());
        $this->assertSame(['enabled' => true, 'sent' => 0, 'already_sent' => 1, 'failed' => 0], $second);
        $this->assertSame(1, DB::table('partner_birthday_greetings')->where('user_id', $dealer->id)->count());

        // Next year is a new greeting.
        $this->travelTo(CarbonImmutable::parse('2027-11-05 09:00:00', 'Asia/Dhaka'));
        $this->artisan('partners:birthday-sms')->assertSuccessful();
        $this->assertCount(2, $this->birthdayTexts());
    }

    public function test_leap_day_birthdays_are_greeted_on_28_february_in_other_years(): void
    {
        $this->turnOn();
        $leapling = $this->partner('dealer', '1996-02-29');
        $bornOn28th = $this->partner('sub_dealer', '1995-02-28');

        $this->travelTo(CarbonImmutable::parse('2027-02-28 09:00:00', 'Asia/Dhaka'));
        $this->artisan('partners:birthday-sms')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$leapling->id, $bornOn28th->id], array_column($this->birthdayTexts(), 'user_id'));

        Config::set('madina.sms.sent', []);
        $this->travelTo(CarbonImmutable::parse('2028-02-28 09:00:00', 'Asia/Dhaka'));
        $this->artisan('partners:birthday-sms')->assertSuccessful();
        $this->assertSame([$bornOn28th->id], array_column($this->birthdayTexts(), 'user_id'));

        Config::set('madina.sms.sent', []);
        $this->travelTo(CarbonImmutable::parse('2028-02-29 09:00:00', 'Asia/Dhaka'));
        $this->artisan('partners:birthday-sms')->assertSuccessful();
        $this->assertSame([$leapling->id], array_column($this->birthdayTexts(), 'user_id'));

        $this->assertTrue(BirthdayGreetings::isBirthday('29/02/1996', CarbonImmutable::parse('2027-02-28')));
        $this->assertFalse(BirthdayGreetings::isBirthday('1996-02-30', CarbonImmutable::parse('2027-03-01')));
        $this->assertFalse(BirthdayGreetings::isBirthday('not a date', CarbonImmutable::parse('2027-03-01')));
    }

    public function test_sms_is_always_written_in_bangla_whatever_language_the_partner_prefers(): void
    {
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'Asia/Dhaka'));
        $bangla = $this->partner('dealer', '1990-11-05', locale: 'bn', profile: ['name_bn' => 'রহিম উদ্দিন']);
        $english = $this->partner('sub_dealer', '1990-11-05', locale: 'en', profile: ['name_bn' => 'করিম']);

        $this->artisan('partners:birthday-sms')->assertSuccessful();

        $texts = collect($this->birthdayTexts())->keyBy('user_id');
        $this->assertSame('মদিনা পরিবারের পক্ষ থেকে শুভ জন্মদিন, রহিম উদ্দিন! আপনার দিনটি ভালো কাটুক।', $texts[$bangla->id]['message']);
        $this->assertSame('মদিনা পরিবারের পক্ষ থেকে শুভ জন্মদিন, করিম! আপনার দিনটি ভালো কাটুক।', $texts[$english->id]['message']);
        foreach ($texts as $text) {
            foreach (['income', 'commission', 'earn', 'order', 'offer', 'আয়', 'কমিশন', 'অর্ডার'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $text['message']);
            }
        }
    }

    public function test_owner_can_turn_the_switch_on_from_dashboard_management(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/dashboards', ['settings' => ['birthday_sms_enabled' => 'true']])
            ->assertSessionHas('success');

        $this->assertTrue(DashboardManagement::get()['birthday_sms_enabled']);
    }

    public function test_command_is_scheduled_daily_at_nine_in_dhaka(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'partners:birthday-sms'));

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame('Asia/Dhaka', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_partner_home_shows_the_birthday_card_only_on_the_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'Asia/Dhaka'));
        $dealer = $this->partner('dealer', '1990-11-05');
        $other = $this->partner('dealer', '1990-11-06');

        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.partner_home.is_birthday', true));
        $this->assertFalse(app(PartnerDashboardHome::class)->for($other)['is_birthday']);
    }

    private function turnOn(): void
    {
        DashboardManagement::save(['birthday_sms_enabled' => true]);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function partner(string $role, ?string $dateOfBirth, ?string $phone = null, string $status = 'active', string $locale = 'en', array $profile = []): User
    {
        $factory = $role === 'dealer' ? User::factory()->dealer() : User::factory()->subDealer();
        $user = $factory->create([
            'phone' => $phone ?? '0175'.str_pad((string) ++$this->phoneCounter, 7, '0', STR_PAD_LEFT),
            'locale' => $locale,
            'profile' => $dateOfBirth === null ? $profile : [...$profile, 'date_of_birth' => $dateOfBirth],
        ]);
        if ($status !== 'active') {
            $user->forceFill(['status' => $status])->save();
        }

        return $user;
    }

    /** @return list<array{user_id: int|null, phone: string, event: string, message: string}> */
    private function birthdayTexts(): array
    {
        return array_values(array_filter(
            config('madina.sms.sent', []),
            fn (array $text): bool => $text['event'] === BirthdayGreetings::Event,
        ));
    }
}
