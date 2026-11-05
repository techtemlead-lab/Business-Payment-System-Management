<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PayoutsHubAndSettlementsPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payouts_hub_pages_requests_and_totals_every_matching_request(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create(['name' => 'Payout Dealer', 'phone' => '01715550031']);

        for ($index = 0; $index < 22; $index++) {
            $this->insertWithdrawal($dealer, [
                'amount' => '100.00',
                'status' => 'requested',
                'destination' => encrypt('01712345678'),
                'created_at' => now()->subMinutes($index),
            ]);
        }
        for ($index = 0; $index < 3; $index++) {
            $this->insertWithdrawal($dealer, ['amount' => '50.00', 'status' => 'approved', 'created_at' => now()->subHours(2)]);
        }
        for ($index = 0; $index < 2; $index++) {
            $this->insertWithdrawal($dealer, [
                'amount' => '200.00',
                'withheld_amount' => '10.00',
                'net_amount' => '190.00',
                'status' => 'paid',
                'paid_at' => now()->subDay(),
                'created_at' => now()->subDays(2),
            ]);
        }
        $this->insertWithdrawal($dealer, ['amount' => '75.00', 'status' => 'rejected', 'created_at' => now()->subDays(3)]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/payouts-hub')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'payouts-hub')
                ->has('data.withdrawals.data', 20)
                ->where('data.withdrawals.current_page', 1)
                ->where('data.withdrawals.last_page', 2)
                ->where('data.withdrawals.per_page', 20)
                ->where('data.withdrawals.total', 28)
                ->where('data.withdrawals.next_page_url', fn ($url) => is_string($url) && str_contains($url, 'page=2'))
                ->where('data.withdrawals.data.0.user_name', 'Payout Dealer')
                ->where('data.withdrawals.data.0.destination_masked', '•••••••5678')
                ->missing('data.withdrawals.data.0.destination')
                ->where('data.payout_totals', [
                    'requested_count' => 22,
                    'requested_amount' => '2200.00',
                    'approved_count' => 3,
                    'approved_amount' => '150.00',
                    'paid_count' => 2,
                    'paid_amount' => '400.00',
                    'paid_withheld_amount' => '20.00',
                ]));

        $this->actingAs($owner)->onPortal('sadmin')->get('/payouts-hub?page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.withdrawals.data', 8)
                ->where('data.withdrawals.current_page', 2)
                ->where('data.payout_totals.requested_count', 22));

        $this->actingAs($owner)->onPortal('sadmin')->get('/payouts-hub?status=paid')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.withdrawals.data', 2)
                ->where('data.withdrawals.total', 2)
                ->where('data.withdrawals.data.0.status', 'paid')
                ->where('data.payout_totals.requested_count', 22)
                ->where('data.payout_totals.paid_amount', '400.00')
                ->where('filters.status', 'paid'));
    }

    public function test_payouts_hub_search_runs_on_the_server_and_treats_wildcards_as_plain_text(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim Traders']);
        $marked = User::factory()->dealer()->create(['name' => 'Karim 100% Pharmacy']);
        for ($index = 0; $index < 21; $index++) {
            $this->insertWithdrawal($dealer, ['amount' => '100.00', 'status' => 'requested', 'payment_reference' => 'WD-'.$index]);
        }
        $this->insertWithdrawal($marked, ['amount' => '60.00', 'status' => 'requested', 'payment_reference' => 'REF_60']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/payouts-hub?q='.urlencode('%'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.withdrawals.data', 1)
                ->where('data.withdrawals.total', 1)
                ->where('data.withdrawals.data.0.user_name', 'Karim 100% Pharmacy')
                ->where('data.payout_totals.requested_count', 1)
                ->where('data.payout_totals.requested_amount', '60.00')
                ->where('filters.q', '%'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/payouts-hub?q=_')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.withdrawals.data', 1)
                ->where('data.withdrawals.data.0.payment_reference', 'REF_60'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/payouts-hub?q=rahim')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.withdrawals.data', 20)
                ->where('data.withdrawals.total', 21)
                ->where('data.withdrawals.next_page_url', fn ($url) => is_string($url) && str_contains($url, 'q=rahim'))
                ->where('data.payout_totals.requested_count', 21)
                ->where('data.payout_totals.requested_amount', '2100.00'));
    }

    public function test_settlements_props_carry_policy_driven_rank_incentive_amounts(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/settlements')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'settlements')
                ->where('data.settlement_state.rank_incentives', [
                    'mo' => '5000.00',
                    'am' => '50000.00',
                    'sm' => '250000.00',
                    'me' => '300000.00',
                ]));

        $this->actingAs($owner)->patchJson('/api/v1/finance/policy', ['rules' => [
            'rank_incentive' => ['parameters' => ['mo_bonus' => '7500.00', 'am_bonus' => '42000.00']],
        ]])->assertOk();

        $this->actingAs($owner)->onPortal('sadmin')->get('/settlements')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.settlement_state.rank_incentives.mo', '7500.00')
                ->where('data.settlement_state.rank_incentives.am', '42000.00')
                ->where('data.settlement_state.rank_incentives.sm', '250000.00'));
    }

    public function test_commission_and_simulator_screens_receive_the_policy_pool_limits(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create();
        $expectedLimits = fn (Assert $page) => $page
            ->where('data.policy_limits.dealer_pool_cap_pct', '40.00')
            ->where('data.policy_limits.sub_dealer_pool_cap_pct', '50.00')
            ->where('data.policy_limits.company_mrp_pct', '30.00')
            ->where('data.policy_limits.sub_dealer_rule_keys', ['additional_rank', 'sub_dealer_salary', 'sub_dealer_tour'])
            ->has('data.policy_limits.dealer_rule_keys', 9);

        $this->actingAs($owner)->onPortal('sadmin')->get('/commissions')->assertOk()->assertInertia($expectedLimits);
        $this->actingAs($owner)->onPortal('sadmin')->get('/simulator')->assertOk()->assertInertia($expectedLimits);
        $this->actingAs($owner)->onPortal('sadmin')->get('/ranks')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.rank_policy.pool_pct', '4.00'));
    }

    public function test_payouts_hub_filters_by_method_and_request_dates_with_matching_totals(): void
    {
        $this->withoutVite();
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->insertWithdrawal($dealer, ['amount' => '100.00', 'status' => 'requested', 'channel' => 'bKash', 'created_at' => now()]);
        $this->insertWithdrawal($dealer, ['amount' => '300.00', 'status' => 'requested', 'channel' => 'Nagad', 'created_at' => now()]);
        $this->insertWithdrawal($dealer, ['amount' => '500.00', 'status' => 'requested', 'channel' => 'bKash', 'created_at' => now()->subMonths(3)]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/payouts-hub?channel=bKash&period=month')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.withdrawals.total', 1)
                ->where('data.withdrawals.data.0.channel', 'bKash')
                ->where('data.payout_totals.requested_count', 1)
                ->where('data.payout_totals.requested_amount', '100.00'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertWithdrawal(User $user, array $attributes): void
    {
        DB::table('withdrawals')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'channel' => 'bKash',
            'destination' => encrypt('01700000000'),
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }
}
