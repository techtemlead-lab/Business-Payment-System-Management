<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan app:readiness` is what the technician runs before go-live: each warning must say in plain words
 * what is wrong and what to set, and the money switches must be visible at a glance.
 */
class ReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_go_live_warning_is_numbered_and_explained_with_the_money_switches_shown(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.auto_release_matured' => false,
            'madina.alerts.telegram_bot_token' => '',
            'madina.alerts.telegram_chat_id' => '',
            'madina.alerts.emails' => '',
            'madina.heartbeat_url' => '',
        ]);

        $this->artisan('app:readiness')
            ->expectsOutputToContain('Money switches: posting OFF (MADINA_FINANCIAL_ACTIVATION) | release of matured commission OFF (MADINA_AUTO_RELEASE_MATURED)')
            ->expectsOutputToContain('[alerts_not_configured] Nobody is told when something serious fails')
            ->expectsOutputToContain('[heartbeat_not_configured] If the server or its timed jobs stop');
    }

    public function test_the_owner_can_read_the_same_list_in_bangla(): void
    {
        config(['madina.alerts.telegram_bot_token' => '', 'madina.alerts.telegram_chat_id' => '', 'madina.alerts.emails' => '']);

        $this->artisan('app:readiness', ['--locale' => 'bn'])
            ->expectsOutputToContain('[alerts_not_configured] বড় কোনো কাজ ব্যর্থ হলে');
    }

    public function test_the_machine_readable_result_carries_the_explanations(): void
    {
        config(['madina.heartbeat_url' => '']);

        $this->artisan('app:readiness', ['--json' => true])
            ->expectsOutputToContain('"heartbeat_not_configured": "If the server or its timed jobs stop');
    }
}
