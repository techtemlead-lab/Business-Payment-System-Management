<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerMonthlyTargets;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerProfileAndTargetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
        $this->seed(CommissionRuleSeeder::class);
    }

    private function partner(?User $sponsor, string $role = 'dealer'): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'sponsor_id' => $sponsor?->id]);
        ReferralTreePath::attachUser($user->id, $sponsor?->id);

        return $user;
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(Request::create('/'))];
    }

    public function test_a_chosen_partner_opens_with_every_level_below_them(): void
    {
        $owner = User::factory()->staff()->create();
        $top = $this->partner(null);
        $branch = $this->partner($top);
        $level3 = $this->partner($branch);
        $level4 = $this->partner($level3, 'sub_dealer');
        $other = $this->partner(null);

        $data = $this->actingAs($owner)->onPortal('sadmin')->withHeaders($this->inertia())
            ->get('/network-tree?tree_root='.$branch->id)->assertOk()->json('props.data');

        $this->assertSame([$branch->id, $level3->id, $level4->id], array_column($data['dealers'], 'id'));
        $this->assertNotContains($other->id, array_column($data['dealers'], 'id'));
        $this->assertSame($branch->id, $data['tree_root']);
        $this->assertSame(3, $data['dealers_total']);
        $this->assertSame(2, $data['dealers'][0]['team_size']);
        $this->assertSame(3, $data['tree_stats']['total']);
    }

    public function test_an_unknown_tree_root_is_ignored(): void
    {
        $owner = User::factory()->staff()->create();
        $this->partner(null);

        $data = $this->actingAs($owner)->onPortal('sadmin')->withHeaders($this->inertia())
            ->get('/network-tree?tree_root=999999')->assertOk()->json('props.data');

        $this->assertArrayNotHasKey('tree_root', $data);
    }

    public function test_the_partner_file_shows_stored_fields_and_the_password_box_to_owners(): void
    {
        $owner = User::factory()->staff()->create();
        $sponsor = $this->partner(null);
        $dealer = $this->partner($sponsor);
        $dealer->forceFill(['nid' => '1234567890', 'profile' => ['name_bn' => 'রহিম', 'father_name' => 'Karim', 'place_code' => 'P-1']])->save();

        $this->actingAs($owner)->onPortal('sadmin')->get('/accounts/'.$dealer->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PartnerProfile')
            ->where('sheet.identity.name_bn', 'রহিম')
            ->where('sheet.identity.father_name', 'Karim')
            ->where('sheet.place.place_code', 'P-1')
            ->where('sheet.kyc.nid', '1234567890')
            ->where('sheet.network.sponsor.id', $sponsor->id)
            ->where('canManagePassword', true)
            ->where('canSetTargets', true)
            ->has('targets', 4));
    }

    public function test_staff_with_the_accounts_right_see_a_masked_id_and_cannot_set_targets(): void
    {
        $staff = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['accounts'], 'action_permissions' => ['accounts' => ['view' => true, 'edit' => true]]]]);
        $dealer = $this->partner(null);
        $dealer->forceFill(['nid' => '1234567890'])->save();

        $this->actingAs($staff)->onPortal('manage')->get('/accounts/'.$dealer->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sheet.kyc.nid', '••••7890')
            ->where('canSetTargets', false));
        $this->actingAs($staff)->onPortal('manage')->post('/commands/accounts/'.$dealer->id.'/monthly-targets', ['month' => '2026-11', 'kind' => 'salary', 'amount' => '5000'])->assertForbidden();
    }

    public function test_staff_without_the_accounts_right_cannot_open_a_partner_file(): void
    {
        $staff = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['reports']]]);
        $dealer = $this->partner(null);

        $this->actingAs($staff)->onPortal('manage')->get('/accounts/'.$dealer->id)->assertForbidden();
    }

    public function test_a_dealer_cannot_open_another_partners_file(): void
    {
        $dealer = $this->partner(null);
        $other = $this->partner(null);

        $this->actingAs($dealer)->onPortal('dealer')->get('/accounts/'.$other->id)->assertNotFound();
    }

    public function test_the_owner_sets_and_clears_a_monthly_target_and_the_dealer_sees_it(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = $this->partner(null);
        $targets = app(PartnerMonthlyTargets::class);

        $default = collect($targets->for($dealer, '2026-11'))->keyBy('kind');
        // The company rule: a dealer's monthly purchase target is ৳30,000 unless the owner sets another (2026-11-21).
        $this->assertSame('30000.00', $default['salary']['target']);
        $this->assertNull($default['salary']['override']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts/'.$dealer->id.'/monthly-targets', ['month' => '2026-11', 'kind' => 'salary', 'amount' => '25000', 'note' => 'Bigger shop'])->assertRedirect();

        $own = collect($targets->for($dealer, '2026-11'))->keyBy('kind')['salary'];
        $this->assertSame('25000.00', $own['target']);
        $this->assertSame('30000.00', $own['default_target']);
        $this->assertSame('Bigger shop', $own['note']);
        $this->assertSame('30000.00', collect($targets->for($dealer, '2026-10'))->keyBy('kind')['salary']['target'], 'Another month keeps the company rule.');
        $this->assertDatabaseHas('audit_events', ['action' => 'partner.target_set', 'subject' => (string) $dealer->id]);

        $home = collect(app(PartnerDashboardHome::class)->for($dealer)['targets'])->keyBy('key');
        $this->assertSame('25000.00', $home['monthly_salary']['target']);
        $this->assertArrayNotHasKey('royalty_volume', $home, 'The card lists only monthly progress, salary and rank.');

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts/'.$dealer->id.'/monthly-targets/clear', ['month' => '2026-11', 'kind' => 'salary'])->assertRedirect();
        $this->assertSame('30000.00', collect($targets->for($dealer, '2026-11'))->keyBy('kind')['salary']['target']);
        $this->assertDatabaseHas('audit_events', ['action' => 'partner.target_cleared', 'subject' => (string) $dealer->id]);
    }

    public function test_a_target_must_be_positive_and_only_for_dealers(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = $this->partner(null);
        $sub = $this->partner($dealer, 'sub_dealer');

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts/'.$dealer->id.'/monthly-targets', ['month' => '2026-11', 'kind' => 'salary', 'amount' => '0'])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts/'.$sub->id.'/monthly-targets', ['month' => '2026-11', 'kind' => 'salary', 'amount' => '500'])->assertNotFound();
        $this->assertSame([], app(PartnerMonthlyTargets::class)->for($sub, '2026-11'));
    }

    public function test_the_incentive_target_can_be_set_like_the_others(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = $this->partner(null);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts/'.$dealer->id.'/monthly-targets', ['month' => '2026-11', 'kind' => 'incentive', 'amount' => '900000'])->assertRedirect();

        $row = collect(app(PartnerMonthlyTargets::class)->for($dealer, '2026-11'))->keyBy('kind')['incentive'];
        $this->assertSame('900000.00', $row['target']);
        $this->assertNotNull($row['override']);
    }
}
