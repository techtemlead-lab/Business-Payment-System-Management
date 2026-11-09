<?php

namespace Tests\Feature;

use App\Domain\Payouts\PayoutReceipts;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** What the browser check of the partner portal found. Source checks stand in for screens that have no script tests. */
class PartnerPortalChecksFoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sub_dealer_is_not_labelled_a_dealer_in_the_team_tree(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);
        $sub = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active', 'sponsor_id' => $dealer->id]);
        ReferralTreePath::attachUser($sub->id, $dealer->id);

        $this->actingAs($dealer)->onPortal('dealer')->get('/network')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.dealers', function ($dealers) use ($sub): bool {
                $this->assertSame('sub_dealer', collect($dealers)->firstWhere('id', $sub->id)['rank']);

                return true;
            }));

        $this->assertStringContainsString('net_badge_sub_dealer', $this->source('components/NetworkTreeExplorer.tsx'));
        $this->assertSame('সাব-ডিলার', __('portal.net_badge_sub_dealer', [], 'bn'));
    }

    public function test_the_tree_view_follows_the_search_box_and_the_rank_filter(): void
    {
        $tree = $this->source('components/NetworkTreeExplorer.tsx');

        $this->assertStringContainsString('shownInTree', $tree, 'The tree must keep only the matching people and those above them.');
        $this->assertMatchesRegularExpression('/shownInTree\?\.has|shownInTree\.has/', $tree);
    }

    public function test_partner_lists_show_at_most_six_columns(): void
    {
        $orders = $this->source('components/office/sections/Orders.tsx');
        $this->assertSame(1, preg_match('/const partnerColumns=\[(.*?)\n\s*\];/s', $orders, $partner), 'The partner order list has its own short column set.');
        $this->assertLessThanOrEqual(6, preg_match_all("/\{key:'/", $partner[1]));

        $network = $this->source('components/NetworkTreeExplorer.tsx');
        $this->assertSame(1, preg_match('/\/\* Table View \*\/(.*?)data=\{filteredDealers\}/s', $network, $team));
        $this->assertLessThanOrEqual(6, preg_match_all("/\bkey: '/", $team[1]), 'Team table');

        $ranks = $this->source('components/RankRoyaltyHub.tsx');
        $this->assertSame(1, preg_match('/\/\* Roster Table \*\/(.*?)data=\{ranks\}/s', $ranks, $roster));
        $this->assertLessThanOrEqual(6, preg_match_all("/\bkey: '/", $roster[1]), 'Ranks table');

        $teamOrders = $this->source('pages/TeamOrders.tsx');
        $this->assertSame(1, preg_match('/columns=\{\[(.*?)\]\}/s', $teamOrders, $columns));
        $this->assertLessThanOrEqual(6, preg_match_all("/\bkey: '/", $columns[1]), 'Team orders table');
    }

    public function test_the_order_status_shows_on_each_row_on_a_phone_without_sideways_movement(): void
    {
        $orders = $this->source('components/office/sections/Orders.tsx');

        $this->assertStringContainsString("phoneColumns={staff?['number','user','total','status','action']:['number','total']}", $orders);
        $this->assertStringContainsString('orders-status-on-phone', $orders);
        $this->assertStringContainsString('.orders-status-on-phone.is-in-list', (string) file_get_contents(resource_path('css/screens-office-orders.css')));
    }

    public function test_a_reward_receipt_has_a_heading_that_fits_a_reward(): void
    {
        $this->assertSame(__('portal.prc_heading'), PayoutReceipts::heading(null));
        $this->assertSame(__('portal.prc_heading_reward'), PayoutReceipts::heading('bike'));
        $this->assertSame(__('portal.prc_heading_reserve'), PayoutReceipts::heading('marketing'));
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(resource_path('js/'.$path));
    }
}
