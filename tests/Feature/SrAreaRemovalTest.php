<?php

namespace Tests\Feature;

use App\Domain\Staff\StaffOperations;
use App\Domain\Staff\TerritoryAccess;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Removing an SR's area ends their access there, needs a reason, and leaves history alone.
 */
class SrAreaRemovalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $salesRep;

    private int $territoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->salesRep = User::factory()->staff('sr')->create();
        $place = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $this->territoryId = app(TerritoryAccess::class)->assign($this->owner, ['staff_id' => $this->salesRep->id, 'place_code' => $place->code]);
    }

    private function remove(User $actor, array $body): TestResponse
    {
        return $this->actingAs($actor)->onPortal('sadmin')->post("/commands/operations/staff/territories/{$this->territoryId}/remove", $body);
    }

    public function test_removing_an_area_needs_only_a_reason_and_keeps_targets(): void
    {
        app(StaffOperations::class)->target($this->owner, ['staff_id' => $this->salesRep->id, 'period_month' => now('Asia/Dhaka')->format('Y-m'), 'target' => '500', 'bonus' => '100']);
        $targetBefore = DB::table('staff_targets')->where('staff_id', $this->salesRep->id)->first();

        $this->remove($this->owner, ['reason' => 'Moved to another district'])->assertSessionHasNoErrors();

        $this->assertSame(__('portal.flash_sr_area_removed'), session('success'));
        $this->assertDatabaseHas('staff_territories', ['id' => $this->territoryId, 'is_active' => false, 'territory_key' => null]);
        $this->assertSame([], app(TerritoryAccess::class)->territories($this->salesRep->id));
        $this->assertEquals($targetBefore, DB::table('staff_targets')->where('staff_id', $this->salesRep->id)->first());

        $audit = DB::table('audit_events')->where('action', 'staff.territory_removed')->where('subject', (string) $this->territoryId)->first();
        $this->assertNotNull($audit);
        $this->assertSame((int) $this->owner->id, (int) $audit->actor_id);
        $this->assertSame('Moved to another district', json_decode((string) $audit->metadata, true)['reason']);

        $this->onPortal('sadmin')->get('/operations/staff-operations')->assertInertia(fn (Assert $page) => $page
            ->where('tables', fn ($tables): bool => collect($tables)->firstWhere('key', 'territories')['page']['data'][0]['is_active'] === false));
    }

    public function test_an_empty_or_too_short_reason_is_refused(): void
    {
        $this->remove($this->owner, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->remove($this->owner, ['reason' => 'abc'])->assertSessionHasErrors('reason');

        $this->assertDatabaseHas('staff_territories', ['id' => $this->territoryId, 'is_active' => true]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'staff.territory_removed']);
    }

    public function test_staff_without_the_staff_edit_right_are_refused(): void
    {
        $inventory = User::factory()->staff('inventory')->create();

        $this->actingAs($inventory)->onPortal('manage')
            ->post("/commands/operations/staff/territories/{$this->territoryId}/remove", ['reason' => 'Should not be allowed'])
            ->assertForbidden();

        $this->assertDatabaseHas('staff_territories', ['id' => $this->territoryId, 'is_active' => true]);
    }
}
