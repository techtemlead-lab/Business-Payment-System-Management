<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerNextSteps;
use App\Domain\Commissions\CommissionPolicy;
use App\Models\DealerRank;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerNextStepsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_new_dealer_sees_what_to_buy_for_salary_and_mo_rank(): void
    {
        $dealer = User::factory()->dealer()->create();
        DealerRank::query()->create(['user_id' => $dealer->id, 'current_rank' => 'dealer', 'lifetime_purchase_total' => '50000.00']);
        $parameters = app(CommissionPolicy::class)->snapshot(false)['rules'];

        $steps = app(PartnerNextSteps::class)->for($dealer);

        $this->assertSame(['salary_buy', 'rank_buy'], array_column($steps, 'kind'));
        $this->assertSame('mo', $steps[1]['values']['rank']);
        $this->assertSame(
            number_format((float) $parameters['rank_royalty']['parameters']['mo_target'] - 50000, 2, '.', ''),
            $steps[1]['values']['left'],
        );
    }

    public function test_steps_never_contain_income_or_award_figures(): void
    {
        $dealer = User::factory()->dealer()->create();
        $steps = app(PartnerNextSteps::class)->for($dealer);

        foreach ($steps as $step) {
            $this->assertArrayNotHasKey('award', $step['values']);
            $this->assertArrayNotHasKey('share', $step['values']);
            $this->assertArrayNotHasKey('estimate', $step['values']);
        }
        foreach (trans('dealer_tools', [], 'en') as $key => $text) {
            if (str_starts_with((string) $key, 'step_')) {
                $this->assertDoesNotMatchRegularExpression('/\b(earn|income|profit|guarantee|will receive|you get)\b/i', (string) $text, (string) $key);
            }
        }
    }

    public function test_higher_rank_dealer_is_told_about_team_leaders_needed(): void
    {
        $dealer = User::factory()->dealer()->create();
        DealerRank::query()->create(['user_id' => $dealer->id, 'current_rank' => 'mo', 'achieved_at' => now()->subYear()]);
        ReferralTreePath::attachUser($dealer->id, null);

        $steps = app(PartnerNextSteps::class)->for($dealer);
        $team = collect($steps)->firstWhere('kind', 'rank_team');

        $this->assertNotNull($team);
        $this->assertSame('am', $team['values']['rank']);
        $this->assertSame('mo', $team['values']['required']);
        $this->assertContains('rank_keep', array_column($steps, 'kind'));
    }

    public function test_sub_dealer_sees_the_no_target_message_on_the_dashboard(): void
    {
        $subDealer = User::factory()->subDealer()->create();

        $this->actingAs($subDealer)->onPortal('subdealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.partner_tools.next_steps.0.kind', 'sub_dealer_keep_ordering'));
    }

    public function test_every_step_has_a_label_in_english_and_bangla(): void
    {
        foreach (['salary_buy', 'salary_met', 'salary_waived', 'rank_keep', 'rank_buy', 'rank_team', 'rank_top', 'sub_dealer_keep_ordering'] as $kind) {
            $this->assertNotSame('dealer_tools.step_'.$kind, __('dealer_tools.step_'.$kind, [], 'en'));
            $this->assertNotSame('dealer_tools.step_'.$kind, __('dealer_tools.step_'.$kind, [], 'bn'));
        }
    }
}
