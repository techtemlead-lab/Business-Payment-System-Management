<?php

namespace Tests\Feature;

use App\Domain\Support\GuidePrerequisites;
use App\Domain\Support\TourGuide;
use App\Domain\Support\UserManual;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserManualGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_user_manual_is_searchable_and_role_aware(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $manual = app(UserManual::class);

        $all = $manual->chaptersFor($dealer);
        $this->assertNotEmpty($all);
        $this->assertTrue(collect($all)->contains(fn ($chapter) => $chapter['key'] === 'catalog_orders'));
        $this->assertFalse(collect($all)->contains(fn ($chapter) => $chapter['key'] === 'staff_ops'));

        $hits = $manual->chaptersFor($dealer, 'withdrawal');
        $this->assertTrue(collect($hits)->contains(fn ($chapter) => $chapter['key'] === 'withdrawals'));

        $this->actingAs($dealer)
            ->onPortal('dealer')
            ->get('/help?q=commission')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('section', 'help')
                ->has('data.manual_sections')
                ->has('data.guide_prerequisites.checklist'));
    }

    public function test_prerequisite_helper_blocks_withdrawals_until_orders_exist(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'phone' => '01710000000']);
        $guide = app(GuidePrerequisites::class)->forSection($dealer, 'withdrawals');

        $this->assertFalse($guide['ready']);
        $this->assertNotEmpty($guide['missing']);
        $this->assertContains('orders', collect($guide['missing'])->pluck('key')->all());
        $this->assertContains('catalog', collect($guide['missing'])->pluck('key')->all());

        $this->actingAs($dealer)
            ->onPortal('dealer')
            ->get('/withdrawals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('section', 'withdrawals')
                ->where('data.guide_prerequisites.ready', false)
                ->where('data.guide_prerequisites.message', fn ($message) => is_string($message) && $message !== ''));

        Order::create([
            'user_id' => $dealer->id,
            'number' => 'MANUAL-1',
            'status' => 'approved',
            'total' => '1000.00',
            'paid' => '1000.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'MANUAL-1'),
        ]);

        $after = app(GuidePrerequisites::class)->forSection($dealer->fresh(), 'withdrawals');
        $this->assertFalse(collect($after['missing'])->contains(fn ($row) => $row['key'] === 'orders'));
    }

    public function test_live_tour_requires_previous_page_completion(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $tours = app(TourGuide::class);

        $earnings = $tours->forPage($dealer, 'earnings');
        $this->assertNotEmpty($earnings['blocked_by']);
        $this->assertSame('orders', $earnings['blocked_by'][0]['key']);
        $this->assertSame([], $earnings['pending']);

        $tours->complete($dealer, 'catalog');
        $tours->complete($dealer, 'orders');
        $ready = $tours->forPage($dealer->fresh(), 'earnings');
        $this->assertSame([], $ready['blocked_by']);
        $this->assertNotEmpty($ready['pending']);
    }

    public function test_live_tours_are_audience_aware_for_owner_staff_dealer_and_subdealer(): void
    {
        $tours = app(TourGuide::class);

        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);
        $staff = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
            'profile' => ['permissions' => ['orders', 'accounts', 'finance', 'catalog']],
        ]);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->assertSame('dealer', $tours->audience($dealer));
        $this->assertSame('sub_dealer', $tours->audience($subDealer));
        $this->assertSame('staff', $tours->audience($staff));
        $this->assertSame('owner', $tours->audience($owner));

        $dealerDefs = $tours->definitionsFor($dealer);
        $subDefs = $tours->definitionsFor($subDealer);
        $staffDefs = $tours->definitionsFor($staff);
        $ownerDefs = $tours->definitionsFor($owner);

        $this->assertArrayHasKey('network', $dealerDefs);
        $this->assertArrayNotHasKey('salary', $dealerDefs);
        $this->assertArrayHasKey('salary', $subDefs);
        $this->assertArrayNotHasKey('network', $subDefs);
        $this->assertGreaterThanOrEqual(5, count($dealerDefs['overview']['steps']));
        $this->assertNotEmpty($dealerDefs['overview']['steps'][0]['tip'] ?? null);

        $this->assertArrayHasKey('accounts', $staffDefs);
        $this->assertArrayHasKey('payouts-hub', $staffDefs);
        $this->assertArrayHasKey('support-admin', $staffDefs);
        $this->assertArrayNotHasKey('earnings', $staffDefs);

        $this->assertArrayHasKey('treasury', $ownerDefs);
        $this->assertArrayHasKey('benefits-fund', $ownerDefs);
        $this->assertSame('owner', $tours->forPage($owner, 'overview')['pending'][0]['audience']);
        $this->assertSame('staff', $tours->forPage($staff, 'overview')['pending'][0]['audience']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('data.tour.pending')
                ->where('data.tour.pending.0.key', 'overview')
                ->where('data.tour.pending.0.audience', 'dealer'));

        $this->actingAs($subDealer)->onPortal('subdealer')->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.tour.pending.0.audience', 'sub_dealer'));

        $this->actingAs($staff)->onPortal('manage')->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.tour.pending.0.audience', 'staff')
                ->where('data.tour.pending.0.key', 'overview'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.tour.pending.0.audience', 'owner'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.tour.blocked_by.0.key', 'overview'));
    }
}
