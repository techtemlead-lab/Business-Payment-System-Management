<?php

namespace Tests\Feature;

use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\DashboardManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When the owner switches the dealers' network tree off, the page and the file download are
 * refused. The two data addresses behind them have to follow the same switch.
 */
class NetworkApiRespectsFeatureSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{User, User} */
    private function dealerAndMember(): array
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $member = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $dealer->id]);
        ReferralTreePath::attachUser($dealer->id, null);
        ReferralTreePath::attachUser($member->id, $dealer->id);

        return [$dealer, $member];
    }

    public function test_the_network_addresses_are_refused_while_the_switch_is_off(): void
    {
        [$dealer, $member] = $this->dealerAndMember();
        DashboardManagement::save(['dealer_network_tree_enabled' => false]);

        $this->actingAs($dealer)->getJson('/api/v1/network')->assertForbidden();
        $this->actingAs($dealer)->getJson('/api/v1/network/'.$member->id.'/orders')->assertForbidden();
    }

    public function test_the_network_addresses_still_work_while_the_switch_is_on(): void
    {
        [$dealer, $member] = $this->dealerAndMember();

        $this->actingAs($dealer)->getJson('/api/v1/network')->assertOk()->assertJsonPath('data.0.id', $member->id);
        $this->actingAs($dealer)->getJson('/api/v1/network/'.$member->id.'/orders')->assertOk();
    }
}
