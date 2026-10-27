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

    public function test_live_overlay_tours_are_active_on_first_login_and_comprehensive(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $tours = app(TourGuide::class);

        $overview = $tours->forPage($dealer, 'overview');
        $this->assertTrue($overview['is_first_login']);
        $this->assertNotEmpty($overview['pending']);
        $this->assertSame('overview', $overview['pending'][0]['key']);
        $this->assertGreaterThanOrEqual(5, count($overview['pending'][0]['steps']));
        $this->assertNotEmpty($overview['all_tours']);
        $this->assertNotEmpty($overview['chapters']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.tour.is_first_login', true)
                ->has('data.tour.pending', 1)
                ->has('data.tour.all_tours')
                ->has('data.tour.chapters'));

        // Once completed, pending is cleared and is_first_login becomes false
        $tours->complete($dealer, 'overview');
        $after = $tours->forPage($dealer, 'overview');
        $this->assertFalse($after['is_first_login']);
        $this->assertSame([], $after['pending']);
        $this->assertContains('overview', $after['completed']);
    }

    public function test_tour_definitions_remain_audience_aware_for_manual_content(): void
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
    }
}
