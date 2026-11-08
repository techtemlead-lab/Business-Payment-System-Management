<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Small wording and button fixes from the signed-in portal check.
 */
class SmallSignedInFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_reserve_fund_choices_have_their_own_name_in_both_languages(): void
    {
        foreach (['additional_rank', 'bike', 'car'] as $program) {
            $key = 'portal.prc_reserve_program_'.$program;
            $this->assertNotSame($key, __($key, [], 'en'));
            $this->assertMatchesRegularExpression('/\p{Bengali}/u', __($key, [], 'bn'));
        }

        $source = (string) file_get_contents(resource_path('js/pages/Operations.tsx'));
        $this->assertStringNotContainsString("choice('program'", $source);
        $this->assertStringContainsString('options:[\'additional_rank\',\'bike\',\'car\'].map(value=>({value,label:reserveProgramLabel(value,translate)}))', $source);
        $this->assertStringContainsString("if (key === 'program' && typeof value === 'string')", $source);
    }

    public function test_a_partner_with_no_refer_code_is_told_there_is_no_poster_by_the_data_the_button_reads(): void
    {
        $dealer = User::factory()->dealer()->create(['refer_code' => null]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.partner_home.profile.refer_code', null));
        $this->actingAs($dealer)->onPortal('dealer')->get('/join-poster/pdf')->assertNotFound();
    }

    public function test_a_partner_with_a_refer_code_gets_the_data_the_button_reads(): void
    {
        $dealer = User::factory()->dealer()->create(['refer_code' => 'MNHC-JOIN-1']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.partner_home.profile.refer_code', 'MNHC-JOIN-1'));
    }

    public function test_both_join_poster_buttons_are_hidden_without_a_refer_code(): void
    {
        $dashboard = (string) file_get_contents(resource_path('js/components/DashboardOverview.tsx'));
        $portal = (string) file_get_contents(resource_path('js/pages/Portal.tsx'));

        $this->assertStringContainsString('{profile.refer_code && <a href={`${base}/join-poster/pdf`}', $dashboard);
        $this->assertStringContainsString('includes(u.role)&&d.profile?.refer_code&&<a className="button small" href={`${base}/join-poster/pdf`}', $portal);
    }
}
