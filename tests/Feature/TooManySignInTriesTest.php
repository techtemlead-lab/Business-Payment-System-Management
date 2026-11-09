<?php

namespace Tests\Feature;

use App\Domain\Auth\LoginCaptcha;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TooManySignInTriesTest extends TestCase
{
    use RefreshDatabase;

    private const RightPassword = 'SyntheticPassword123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        Config::set('madina.preview_mode', false);
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        Config::set('madina.login_otp.enabled', false);
    }

    /** @param  array<string, mixed>  $attributes */
    private function account(string $role = 'dealer', array $attributes = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'password' => self::RightPassword,
            'phone' => '01712345678',
            ...$attributes,
        ]);
    }

    private function tryToSignIn(string $portal, string $identity, string $password): TestResponse
    {
        $this->app['session']->put(LoginCaptcha::SessionKey, ['text' => 'ABCDE', 'expires_at' => now()->addMinutes(2)->getTimestamp()]);

        return $this->onPortal($portal)->post('/login', ['email' => $identity, 'password' => $password, 'captcha' => 'ABCDE']);
    }

    private function useUpTheTries(string $portal, string $identity, int $tries = 5): void
    {
        for ($i = 0; $i < $tries; $i++) {
            $this->tryToSignIn($portal, $identity, 'WrongPassword123')->assertSessionHasErrors('email');
        }
    }

    /** @return array<string, array{string, string}> */
    public static function portals(): array
    {
        return [
            'owner portal' => ['sadmin', 'super_admin'],
            'staff portal' => ['manage', 'finance'],
            'dealer portal' => ['dealer', 'dealer'],
            'sub-dealer portal' => ['subdealer', 'sub_dealer'],
        ];
    }

    #[DataProvider('portals')]
    public function test_the_sixth_try_is_refused_even_with_the_right_password(string $portal, string $role): void
    {
        $account = $this->account($role);
        $this->useUpTheTries($portal, $account->email);

        $this->tryToSignIn($portal, $account->email, self::RightPassword)->assertSessionHasErrors('email');

        $this->assertGuest();
        $message = (string) session('errors')->first('email');
        $this->assertStringContainsString('Too many tries', $message);
        $this->assertMatchesRegularExpression('/\d+/', $message, 'The person is told how long to wait.');
        $this->assertTrue(\DB::table('audit_events')->where('action', 'login.failed')->where('metadata', 'like', '%"reason":"locked"%')->exists());
    }

    public function test_the_refusal_is_in_bangla_for_a_bangla_visitor(): void
    {
        $account = $this->account();
        $this->app['session']->put('locale', 'bn');
        $this->useUpTheTries('dealer', $account->email);

        $this->tryToSignIn('dealer', $account->email, self::RightPassword)->assertSessionHasErrors('email');

        $this->assertStringContainsString('অনেকবার চেষ্টা', (string) session('errors')->first('email'));
    }

    public function test_someone_else_on_the_same_computer_can_still_sign_in(): void
    {
        $locked = $this->account('dealer', ['phone' => '01712345678']);
        $someoneElse = $this->account('dealer', ['phone' => '01798765432']);
        $this->useUpTheTries('dealer', $locked->email);

        $this->tryToSignIn('dealer', $someoneElse->email, self::RightPassword)->assertRedirect();

        $this->assertAuthenticatedAs($someoneElse);
    }

    public function test_the_right_password_works_again_after_the_wait(): void
    {
        $account = $this->account();
        $this->useUpTheTries('dealer', $account->email);
        $this->tryToSignIn('dealer', $account->email, self::RightPassword)->assertSessionHasErrors('email');

        $this->travel(61)->seconds();

        $this->tryToSignIn('dealer', $account->email, self::RightPassword)->assertRedirect();
        $this->assertAuthenticatedAs($account);
    }

    public function test_a_correct_sign_in_clears_the_count(): void
    {
        $account = $this->account();
        $this->useUpTheTries('dealer', $account->email, 4);
        $this->tryToSignIn('dealer', $account->email, self::RightPassword)->assertRedirect();
        $this->assertAuthenticatedAs($account);
        auth()->logout();

        // Four more wrong tries would have gone over the limit if the earlier ones still counted.
        $this->useUpTheTries('dealer', $account->email, 4);
        $this->tryToSignIn('dealer', $account->email, self::RightPassword)->assertRedirect();
        $this->assertAuthenticatedAs($account);
    }

    public function test_wrong_tries_spread_over_many_addresses_still_lock_the_account(): void
    {
        $account = $this->account();
        for ($i = 1; $i <= 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$i]);
            $this->tryToSignIn('dealer', $account->email, 'WrongPassword123')->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.200']);
        $this->tryToSignIn('dealer', $account->email, self::RightPassword)->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
