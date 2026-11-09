<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A form's refusal is kept in the session for one page load. A timed refresh that lands just
 * before that page must not use it up, or the form closes as if it had saved.
 */
class BackgroundRefreshKeepsFormErrorsTest extends TestCase
{
    use RefreshDatabase;

    private const OneTimeMessage = ['warning' => 'Refused', '_flash' => ['old' => ['warning'], 'new' => []]];

    public function test_a_timed_refresh_keeps_the_one_time_message(): void
    {
        $this->openOrders(['X-Background-Refresh' => '1']);

        $this->assertSame('Refused', session('warning'));
    }

    public function test_a_prefetch_keeps_the_one_time_message(): void
    {
        $this->openOrders(['Purpose' => 'prefetch']);

        $this->assertSame('Refused', session('warning'));
    }

    public function test_opening_the_notification_bell_keeps_the_one_time_message(): void
    {
        $this->actingAs(User::factory()->staff('super_admin')->create())->onPortal('sadmin')
            ->withSession(self::OneTimeMessage)
            ->getJson('/commands/notices/latest')->assertOk();

        $this->assertSame('Refused', session('warning'));
    }

    public function test_a_normal_page_load_still_uses_it_up(): void
    {
        $this->openOrders([]);

        $this->assertNull(session('warning'));
    }

    public function test_every_timed_refresh_says_it_is_a_background_one(): void
    {
        foreach (['pages/DataImports', 'pages/OrderDetail', 'pages/WithdrawalDetail', 'pages/Portal', 'components/PartnerChat'] as $file) {
            $source = (string) file_get_contents(resource_path("js/{$file}.tsx"));
            $this->assertSame(substr_count($source, 'usePoll('), preg_match_all('/headers:\s*backgroundRefreshHeaders/', $source), $file);
        }
    }

    /** @param  array<string, string>  $headers */
    private function openOrders(array $headers): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->staff('super_admin')->create())->onPortal('sadmin')
            ->withSession(self::OneTimeMessage)
            ->get('/orders', $headers)->assertOk();
    }
}
