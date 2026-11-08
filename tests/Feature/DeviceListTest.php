<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Each person sees and logs out only their own devices, and raw session ids never leave the server.
 */
class DeviceListTest extends TestCase
{
    use RefreshDatabase;

    private const ChromeOnWindows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    private const SafariOnIphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    public function test_the_list_shows_only_my_devices_and_marks_this_one(): void
    {
        $me = User::factory()->dealer()->create();
        $current = Str::random(40);
        $this->sessionFor($me, $current, self::ChromeOnWindows);
        $this->sessionFor($me, Str::random(40), self::SafariOnIphone);
        $this->sessionFor(User::factory()->dealer()->create(), Str::random(40), self::SafariOnIphone);

        $response = $this->devices($me, $current)->assertOk()->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'))->keyBy('label');
        $this->assertTrue($rows['Chrome on Windows']['current']);
        $this->assertFalse($rows['Safari on iPhone']['current']);
    }

    public function test_raw_session_ids_are_never_sent_to_the_page(): void
    {
        $me = User::factory()->staff()->create();
        $current = Str::random(40);
        $other = Str::random(40);
        $this->sessionFor($me, $current, self::ChromeOnWindows);
        $this->sessionFor($me, $other, self::SafariOnIphone);

        $body = $this->devices($me, $current, 'sadmin')->assertOk()->getContent();

        $this->assertStringNotContainsString($other, $body);
        $this->assertStringNotContainsString($current, $body);
    }

    public function test_a_person_can_log_out_another_of_their_own_devices(): void
    {
        $me = User::factory()->dealer()->create(['password' => Hash::make('Right-Pass-1')]);
        $current = Str::random(40);
        $other = Str::random(40);
        $this->sessionFor($me, $current, self::ChromeOnWindows);
        $this->sessionFor($me, $other, self::SafariOnIphone);
        $handle = $this->handleFor($me, $current, 'Safari on iPhone');

        $this->withCredentials()->withCookie((string) config('session.cookie'), $current)->actingAs($me)->onPortal('dealer')
            ->postJson('/commands/devices/'.$handle.'/logout', ['current_password' => 'Right-Pass-1'])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseMissing('sessions', ['id' => $other]);
        $this->assertDatabaseHas('sessions', ['id' => $current]);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $me->id, 'action' => 'security.device_logged_out']);
    }

    public function test_the_wrong_password_logs_nothing_out(): void
    {
        $me = User::factory()->dealer()->create(['password' => Hash::make('Right-Pass-1')]);
        $current = Str::random(40);
        $other = Str::random(40);
        $this->sessionFor($me, $current, self::ChromeOnWindows);
        $this->sessionFor($me, $other, self::SafariOnIphone);
        $handle = $this->handleFor($me, $current, 'Safari on iPhone');

        $this->withCredentials()->withCookie((string) config('session.cookie'), $current)->actingAs($me)->onPortal('dealer')
            ->postJson('/commands/devices/'.$handle.'/logout', ['current_password' => 'Wrong-Pass-1'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('sessions', ['id' => $other]);
    }

    public function test_another_persons_device_cannot_be_logged_out(): void
    {
        $me = User::factory()->dealer()->create(['password' => Hash::make('Right-Pass-1')]);
        $victim = User::factory()->dealer()->create();
        $victimSession = Str::random(40);
        $this->sessionFor($victim, $victimSession, self::SafariOnIphone);
        $victimHandle = $this->handleFor($victim, Str::random(40), 'Safari on iPhone');

        $this->actingAs($me)->onPortal('dealer')
            ->postJson('/commands/devices/'.$victimHandle.'/logout', ['current_password' => 'Right-Pass-1'])
            ->assertNotFound();

        $this->assertDatabaseHas('sessions', ['id' => $victimSession]);
    }

    public function test_the_current_device_is_not_logged_out_from_the_list(): void
    {
        $me = User::factory()->dealer()->create(['password' => Hash::make('Right-Pass-1')]);
        $current = Str::random(40);
        $this->sessionFor($me, $current, self::ChromeOnWindows);
        $handle = $this->handleFor($me, $current, 'Chrome on Windows');

        $this->withCredentials()->withCookie((string) config('session.cookie'), $current)->actingAs($me)->onPortal('dealer')
            ->postJson('/commands/devices/'.$handle.'/logout', ['current_password' => 'Right-Pass-1'])
            ->assertNotFound();

        $this->assertDatabaseHas('sessions', ['id' => $current]);
    }

    public function test_office_staff_with_a_limited_role_can_log_out_their_own_device(): void
    {
        $me = User::factory()->staff('employee')->create(['password' => Hash::make('Right-Pass-1'), 'profile' => ['permissions' => ['catalog']]]);
        $current = Str::random(40);
        $other = Str::random(40);
        $this->sessionFor($me, $current, self::ChromeOnWindows);
        $this->sessionFor($me, $other, self::SafariOnIphone);
        $handle = $this->handleFor($me, $current, 'Safari on iPhone', 'manage');

        $this->withCredentials()->withCookie((string) config('session.cookie'), $current)->actingAs($me)->onPortal('manage')
            ->postJson('/commands/devices/'.$handle.'/logout', ['current_password' => 'Right-Pass-1'])
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => $other]);
    }

    public function test_guests_cannot_see_the_list(): void
    {
        $this->onPortal('dealer')->getJson('/commands/devices')->assertUnauthorized();
    }

    private function devices(User $user, string $currentSessionId, string $portal = 'dealer'): TestResponse
    {
        return $this->withCredentials()->withCookie((string) config('session.cookie'), $currentSessionId)->actingAs($user)->onPortal($portal)->getJson('/commands/devices');
    }

    private function handleFor(User $user, string $currentSessionId, string $label, string $portal = 'dealer'): string
    {
        return (string) collect($this->devices($user, $currentSessionId, $portal)->assertOk()->json('data'))->firstWhere('label', $label)['handle'];
    }

    private function sessionFor(User $user, string $id, string $userAgent): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => $userAgent, 'payload' => '', 'last_activity' => now()->timestamp]);
    }
}
