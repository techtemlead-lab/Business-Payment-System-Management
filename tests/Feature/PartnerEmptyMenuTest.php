<?php

namespace Tests\Feature;

use App\Domain\Support\PartnerEmptySections;
use App\Models\PartnerContest;
use App\Models\PartnerEvent;
use App\Models\SalesKitItem;
use App\Models\TrainingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Menu pages with nothing in them are hidden from a partner until the company adds content (simplify line). */
class PartnerEmptyMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_new_company_hides_all_four_empty_pages(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->assertEqualsCanonicalizing(['training', 'events', 'sales-kit', 'contests'], app(PartnerEmptySections::class)->for($dealer));
    }

    public function test_a_page_shows_again_once_it_has_content(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        TrainingItem::factory()->published()->create(['audience' => 'all']);
        PartnerEvent::factory()->published()->create();
        $owner = User::factory()->create(['role' => 'super_admin']);
        PartnerContest::query()->create(['title' => 'March race', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addWeek()->toDateString(), 'metric' => 'sales', 'created_by' => $owner->id]);

        $this->assertSame(['sales-kit'], app(PartnerEmptySections::class)->for($dealer));
        SalesKitItem::query()->create(['title' => 'Poster', 'kind' => 'poster', 'language' => 'bn', 'file_path' => 'x/poster.png', 'file_name' => 'poster.png', 'mime' => 'image/png', 'size' => 10, 'uploaded_by' => $owner->id]);
        $this->assertSame([], app(PartnerEmptySections::class)->for($dealer));
    }

    public function test_staff_are_not_affected_and_the_page_carries_the_list(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->assertSame([], app(PartnerEmptySections::class)->for($owner));

        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('emptyPartnerSections', fn ($list): bool => in_array('training', collect($list)->all(), true)));
    }
}
