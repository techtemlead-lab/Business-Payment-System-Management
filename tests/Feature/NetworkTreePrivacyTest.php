<?php

namespace Tests\Feature;

use App\Domain\Pricing\ProductPricing;
use App\Http\Portals\Shared\Sections\NetworkSection;
use App\Http\Portals\Shared\Sections\SectionContext;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The company-wide partner tree (every dealer and sub-dealer, with sales and earnings)
 * is only ever sent to staff. Partners only ever see their own downline.
 */
class NetworkTreePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private User $child;

    private User $grandchild;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->root = User::factory()->dealer()->create(['name' => 'Root Dealer']);
        $this->child = User::factory()->dealer()->create(['name' => 'Child Dealer', 'sponsor_id' => $this->root->id]);
        $this->grandchild = User::factory()->subDealer()->create(['name' => 'Grandchild Sub-dealer', 'sponsor_id' => $this->child->id]);
        $this->outsider = User::factory()->dealer()->create(['name' => 'Outside Dealer']);
        ReferralTreePath::attachUser($this->root->id, null);
        ReferralTreePath::attachUser($this->child->id, $this->root->id);
        ReferralTreePath::attachUser($this->grandchild->id, $this->child->id);
        ReferralTreePath::attachUser($this->outsider->id, null);
    }

    public function test_partner_portals_do_not_offer_the_company_network_tree(): void
    {
        $this->actingAs($this->child)->onPortal('dealer')->get('/network-tree')->assertNotFound();
        $this->actingAs($this->grandchild)->onPortal('subdealer')->get('/network-tree')->assertNotFound();
    }

    public function test_a_dealer_only_receives_their_own_downline_on_the_network_page(): void
    {
        $this->actingAs($this->child)->onPortal('dealer')->get('/network')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.dealers', function ($dealers): bool {
                $ids = collect($dealers)->pluck('id')->sort()->values()->all();

                $this->assertSame([$this->child->id, $this->grandchild->id], $ids);

                return true;
            }));
    }

    public function test_the_network_section_never_builds_the_company_tree_for_a_partner(): void
    {
        $treeProps = app(NetworkSection::class)->data($this->partnerContext($this->child, 'network-tree'), []);
        $this->assertArrayNotHasKey('dealers', $treeProps, 'No partner tree is built for a partner on network-tree.');
        $this->assertArrayNotHasKey('dealers_total', $treeProps);

        $networkProps = app(NetworkSection::class)->data($this->partnerContext($this->child, 'network'), []);
        $ids = collect($networkProps['dealers'])->pluck('id')->sort()->values()->all();
        $this->assertSame([$this->child->id, $this->grandchild->id], $ids, 'Only the dealer and their own downline.');
        $this->assertSame(2, $networkProps['dealers_total']);
    }

    public function test_staff_still_receive_the_full_partner_tree(): void
    {
        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/network-tree')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.dealers_total', 4)
                ->where('data.dealers', function ($dealers): bool {
                    $ids = collect($dealers)->pluck('id')->sort()->values()->all();

                    $this->assertSame([$this->root->id, $this->child->id, $this->grandchild->id, $this->outsider->id], $ids);

                    return true;
                }));
    }

    private function partnerContext(User $partner, string $section): SectionContext
    {
        return new SectionContext(
            request: Request::create('/'.$section),
            user: $partner,
            portal: 'dealer',
            section: $section,
            isStaff: false,
            usingStaffWorkspace: false,
            catalogPortal: 'dealer',
            orders: Order::query()->where('user_id', $partner->id),
            pricing: app(ProductPricing::class),
        );
    }
}
