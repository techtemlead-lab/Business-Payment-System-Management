<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerProfileCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The partner-side join link is off by default: partners never join anyone, the office sets the sponsor. */
class PartnerJoinLinkSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): User
    {
        return User::factory()->dealer()->create([
            'status' => 'active',
            'refer_code' => 'REF-SWITCH-01',
            'dealer_code' => 'REF-SWITCH-01',
        ]);
    }

    public function test_the_switch_is_off_in_the_config_file(): void
    {
        $this->assertStringContainsString("env('MADINA_PARTNER_JOIN_LINK', false)", (string) file_get_contents(base_path('config/madina.php')));
    }

    public function test_with_the_link_off_nothing_on_the_partner_side_offers_it(): void
    {
        config(['madina.partner_join_link_enabled' => false]);
        $dealer = $this->partner();

        $card = app(PartnerProfileCard::class)->for($dealer);
        $this->assertNull($card['refer_url']);
        $this->assertSame('REF-SWITCH-01', $card['refer_code']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/join-poster/pdf')->assertNotFound();
        $this->actingAs($dealer)->onPortal('dealer')->get('/referral-qr.svg')->assertNotFound();
        $this->assertNull(app(PartnerDashboardHome::class)->for($dealer)['referral']);
    }

    public function test_with_the_link_off_the_public_join_address_never_opens_an_application_under_a_partner(): void
    {
        config(['madina.partner_join_link_enabled' => false]);
        $this->partner();

        $this->onPortal('public')->get('/join/REF-SWITCH-01')->assertRedirect('/partnership#partner-apply');
    }

    public function test_with_the_link_on_everything_works_as_before(): void
    {
        config(['madina.partner_join_link_enabled' => true]);
        $dealer = $this->partner();

        $this->assertStringEndsWith('/join/REF-SWITCH-01', (string) app(PartnerProfileCard::class)->for($dealer)['refer_url']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/referral-qr.svg')->assertOk();
        $this->onPortal('public')->get('/join/REF-SWITCH-01')->assertOk();
    }
}
