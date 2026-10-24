<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicReferralJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_join_link_redirects_to_partnership_with_sponsor_code(): void
    {
        $this->onPortal('public')
            ->get('/join/ref-partner-01')
            ->assertRedirect('/partnership?sponsor_code=REF-PARTNER-01#partner-apply');
    }

    public function test_partnership_page_prefills_sponsor_code_from_query(): void
    {
        $this->onPortal('public')
            ->get('/partnership?sponsor_code=REF-ABC')
            ->assertOk()
            ->assertSee('name="sponsor_code"', false)
            ->assertSee('value="REF-ABC"', false);
    }

    public function test_partner_home_exposes_public_referral_url(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'refer_code' => 'REF-HOME-88',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('data.partner_home.referral.code', 'REF-HOME-88')
                ->where('data.partner_home.referral.url', PortalHost::url('public', '/join/REF-HOME-88')));
    }
}
