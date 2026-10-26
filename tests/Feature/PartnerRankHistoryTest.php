<?php

namespace Tests\Feature;

use App\Models\DealerRank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PartnerRankHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_partner_ranks_payload_includes_qualification_history(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        DealerRank::query()->create([
            'user_id' => $dealer->id,
            'current_rank' => 'mo',
            'achieved_at' => now()->subDays(3),
            'qualification_history' => [
                ['rank' => 'mo', 'achieved_at' => now()->subDays(3)->toIso8601String()],
            ],
            'lifetime_purchase_total' => '250000.00',
            'monthly_purchase_total' => '10000.00',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/ranks')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portals/dealer/Dashboard')
                ->has('data.ranks', 1)
                ->where('data.ranks.0.current_rank', 'mo')
                ->where('data.ranks.0.qualification_history.0.rank', 'mo')
                ->has('data.ranks.0.qualification_history.0.achieved_at'));
    }

    public function test_partner_ranks_do_not_include_other_dealers_history(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        DealerRank::query()->create([
            'user_id' => $other->id,
            'current_rank' => 'am',
            'achieved_at' => now(),
            'qualification_history' => [
                ['rank' => 'mo', 'achieved_at' => now()->subMonth()->toIso8601String()],
                ['rank' => 'am', 'achieved_at' => now()->toIso8601String()],
            ],
        ]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/ranks')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portals/dealer/Dashboard')
                ->has('data.ranks', 0));
    }
}
