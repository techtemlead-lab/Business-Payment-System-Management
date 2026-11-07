<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JoinAnniversaryGreetingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->travelTo(CarbonImmutable::parse('2026-11-07 09:05:00', 'Asia/Dhaka'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_partners_who_joined_on_this_day_get_one_in_app_greeting_a_year_and_no_sms(): void
    {
        $twoYears = $this->partner('dealer', '2024-11-07 10:00:00', 'en');
        $oneYear = $this->partner('sub_dealer', '2025-11-07 01:00:00', 'bn');
        $lateNightDhaka = $this->partner('dealer', '2023-11-06 20:00:00', 'en');
        $joinedToday = $this->partner('dealer', '2026-11-07 03:00:00', 'en');
        $otherDay = $this->partner('dealer', '2024-11-08 10:00:00', 'en');
        $suspended = $this->partner('dealer', '2024-11-07 10:00:00', 'en', 'suspended');
        $staff = User::factory()->staff()->create();
        DB::table('users')->where('id', $staff->id)->update(['created_at' => '2024-11-07 10:00:00']);

        $this->artisan('partners:anniversary-greetings')->assertSuccessful();
        $this->artisan('partners:anniversary-greetings')->assertSuccessful();

        foreach ([$twoYears, $oneYear, $lateNightDhaka] as $partner) {
            $this->assertSame(1, DB::table('account_notices')->where('user_id', $partner->id)->count());
        }
        foreach ([$joinedToday, $otherDay, $suspended, $staff] as $user) {
            $this->assertSame(0, DB::table('account_notices')->where('user_id', $user->id)->count());
        }
        $this->assertStringContainsString('2 years since you joined', (string) DB::table('account_notices')->where('user_id', $twoYears->id)->value('body'));
        $this->assertStringContainsString('3 years since you joined', (string) DB::table('account_notices')->where('user_id', $lateNightDhaka->id)->value('body'), 'The join day is counted on the Dhaka calendar.');
        $this->assertSame(__('portal.notice_join_anniversary_title', [], 'bn'), DB::table('account_notices')->where('user_id', $oneYear->id)->value('title'));
        $this->assertSame([], config('madina.sms.sent'));
    }

    private function partner(string $role, string $createdAtUtc, string $locale, string $status = 'active'): User
    {
        $partner = User::factory()->create(['role' => $role, 'status' => $status, 'locale' => $locale]);
        DB::table('users')->where('id', $partner->id)->update(['created_at' => $createdAtUtc]);

        return $partner->fresh();
    }
}
