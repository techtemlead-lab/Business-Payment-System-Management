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
}
