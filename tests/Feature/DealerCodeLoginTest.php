<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class DealerCodeLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        Config::set('madina.login_otp.enabled', false);
    }

    public function test_partner_can_sign_in_with_dealer_code(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MNHC-1001',
            'password' => 'SyntheticPassword123',
            'phone' => null,
        ]);

        $this->onPortal('dealer')
            ->post('/login', ['email' => 'mnhc-1001', 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
    }

    public function test_login_rate_limit_reports_remaining_seconds(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MNHC-2002',
            'password' => 'SyntheticPassword123',
            'phone' => null,
        ]);
        $key = 'MNHC-2002|127.0.0.1';
        RateLimiter::clear($key);
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->onPortal('dealer')
            ->post('/login', ['email' => 'MNHC-2002', 'password' => 'WrongPassword123'])
            ->assertSessionHasErrors('email');
        $message = session('errors')->first('email');
        $this->assertMatchesRegularExpression('/\d+/', (string) $message);
        $this->assertStringContainsString('seconds', strtolower((string) $message));
        unset($dealer);
    }

    public function test_bangla_locale_files_are_valid_utf8_with_bengali_script(): void
    {
        foreach (['lang/bn/site.php', 'lang/bn/portal.php'] as $path) {
            $bytes = file_get_contents(base_path($path));
            $this->assertNotFalse($bytes);
            $this->assertTrue(mb_check_encoding($bytes, 'UTF-8'));
            $this->assertStringNotContainsString("\u{FFFD}", $bytes);
            $this->assertMatchesRegularExpression('/[\x{0980}-\x{09FF}]/u', $bytes);
            $this->assertIsArray(include base_path($path));
        }
    }
}
