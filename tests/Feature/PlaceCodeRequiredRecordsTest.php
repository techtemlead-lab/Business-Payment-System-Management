<?php

namespace Tests\Feature;

use App\Domain\Staff\TerritoryAccess;
use App\Models\AreaDealerAssignment;
use App\Models\CompanyBranch;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\DeploymentReadiness;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Branches, area seats, staff territories and partner applications must carry a real
 * place code; names are filled in from it and never used to match a place.
 */
class PlaceCodeRequiredRecordsTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $rangpur;

    private PlaceCode $rangpurDistrict;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->rangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $this->rangpurDistrict = PlaceCode::factory()->district($this->rangpur)->create(['name' => 'Kurigram']);
    }

    public function test_a_branch_cannot_be_created_without_a_place_code_and_takes_its_names_from_the_code(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->postJson('/api/v1/branches', ['code' => 'NO-PLACE', 'name' => 'Nameless hub', 'division' => 'Rangpur'])
            ->assertUnprocessable()->assertJsonValidationErrors('place_code');
        $this->postJson('/api/v1/branches', ['code' => 'BAD-PLACE', 'name' => 'Bad hub', 'place_code' => 'MNHC-999999'])
            ->assertUnprocessable()->assertJsonValidationErrors('place_code');
        $this->assertDatabaseMissing('company_branches', ['code' => 'NO-PLACE']);

        $this->postJson('/api/v1/branches', ['code' => 'KUR-01', 'name' => 'Kurigram hub', 'place_code' => $this->rangpurDistrict->code])
            ->assertCreated();

        $this->assertDatabaseHas('company_branches', ['code' => 'KUR-01', 'place_code_id' => $this->rangpurDistrict->id,
            'division' => 'Rangpur', 'district' => 'Kurigram']);
    }

    public function test_editing_a_branch_keeps_its_place_code_and_a_branch_without_one_must_get_one(): void
    {
        $owner = User::factory()->staff()->create();
        $placed = CompanyBranch::factory()->create(['place_code_id' => $this->rangpurDistrict->id]);
        $unplaced = CompanyBranch::factory()->create();

        $this->actingAs($owner)->patchJson('/api/v1/branches/'.$placed->id, ['code' => $placed->code, 'name' => 'Renamed hub'])->assertOk();
        $this->assertDatabaseHas('company_branches', ['id' => $placed->id, 'name' => 'Renamed hub', 'place_code_id' => $this->rangpurDistrict->id]);

        $this->patchJson('/api/v1/branches/'.$unplaced->id, ['code' => $unplaced->code, 'name' => 'Still nowhere'])
            ->assertUnprocessable()->assertJsonValidationErrors('place_code');
    }

    public function test_an_area_seat_cannot_be_created_from_names_only(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($admin)->postJson('/commands/area-dealers', ['user_id' => $dealer->id, 'level' => 'division',
            'division' => 'Rangpur', 'zone_name' => 'Rangpur', 'monthly_quota' => '0.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('place_code_id');

        $this->assertSame(0, AreaDealerAssignment::query()->count());
    }

    public function test_the_database_refuses_an_active_area_seat_without_a_place_code(): void
    {
        $dealer = User::factory()->dealer()->create();

        $this->expectException(QueryException::class);
        AreaDealerAssignment::query()->create(['user_id' => $dealer->id, 'level' => 'division', 'zone_name' => 'Rangpur',
            'division' => 'Rangpur', 'monthly_quota' => '0', 'is_active' => true]);
    }

    public function test_a_staff_territory_needs_a_place_code_and_stores_it(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sr = User::factory()->create(['role' => 'sr']);

        $this->actingAs($admin)->postJson('/api/v1/operations/staff/territories', ['staff_id' => $sr->id, 'level' => 'division', 'division' => 'Rangpur'])
            ->assertUnprocessable()->assertJsonValidationErrors('place_code');
        $this->postJson('/api/v1/operations/staff/territories', ['staff_id' => $sr->id, 'place_code' => $this->rangpurDistrict->code])->assertOk();

        $this->assertDatabaseHas('staff_territories', ['staff_id' => $sr->id, 'place_code_id' => $this->rangpurDistrict->id,
            'level' => 'district', 'division' => 'Rangpur', 'district' => 'Kurigram']);
    }

    public function test_a_field_rep_only_sees_partners_inside_their_territory_place_code_never_by_name(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sr = User::factory()->create(['role' => 'sr']);
        $territories = app(TerritoryAccess::class);
        $territories->assign($admin, ['staff_id' => $sr->id, 'place_code' => $this->rangpur->code]);
        $otherRangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);

        $inside = User::factory()->inPlace(PlaceCode::factory()->union(PlaceCode::factory()->upazila($this->rangpurDistrict)->create())->create())->create();
        $sameNameElsewhere = User::factory()->inPlace($otherRangpur)->create();
        $namesOnly = User::factory()->create(['division' => 'Rangpur', 'district' => 'Kurigram']);

        $visible = $territories->partners($sr->fresh())->pluck('id')->all();

        $this->assertSame([$inside->id], $visible);
        $this->assertNotContains($sameNameElsewhere->id, $visible);
        $this->assertNotContains($namesOnly->id, $visible);
    }

    public function test_sr_applications_need_a_place_code_inside_the_territory_and_approval_saves_it(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sr = User::factory()->create(['role' => 'sr']);
        app(TerritoryAccess::class)->assign($admin, ['staff_id' => $sr->id, 'place_code' => $this->rangpur->code]);
        $elsewhere = PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Dhaka']))->create();
        $payload = ['name' => 'New dealer', 'email' => 'placed-partner@example.test', 'phone' => '01711111112', 'role' => 'dealer'];

        $this->actingAs($sr)->postJson('/api/v1/operations/staff/applications', [...$payload, 'division' => 'Rangpur'])
            ->assertUnprocessable()->assertJsonValidationErrors('place_code');
        $this->postJson('/api/v1/operations/staff/applications', [...$payload, 'place_code' => $elsewhere->code])->assertForbidden();
        $id = $this->postJson('/api/v1/operations/staff/applications', [...$payload, 'place_code' => $this->rangpurDistrict->code])
            ->assertOk()->json('data.id');

        $this->actingAs($admin)->postJson('/api/v1/operations/staff/applications/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Identity and territory reviewed'])
            ->assertOk();

        $partner = User::query()->where('email', $payload['email'])->firstOrFail();
        $this->assertSame($this->rangpurDistrict->id, $partner->profile['place_code_id']);
        $this->assertSame('Kurigram', $partner->district);
    }

    public function test_approving_an_application_without_any_place_code_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $id = (string) Str::uuid();
        DB::table('dealer_applications')->insert(['id' => $id, 'email' => 'nameonly@example.test', 'status' => 'pending', 'source' => 'public',
            'data' => json_encode(['name' => 'Name only', 'email' => 'nameonly@example.test', 'phone' => '01711111113', 'role' => 'dealer',
                'division' => 'Rangpur', 'district' => 'Kurigram']), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->postJson('/api/v1/operations/staff/applications/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Looks fine'])
            ->assertUnprocessable()->assertJsonValidationErrors('place_code');
        $this->assertDatabaseMissing('users', ['email' => 'nameonly@example.test']);

        $this->postJson('/api/v1/operations/staff/applications/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Place code checked',
            'place_code' => $this->rangpurDistrict->code])->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'nameonly@example.test', 'district' => 'Kurigram']);
    }

    public function test_readiness_lists_active_records_saved_without_a_place_code(): void
    {
        $unplacedBranch = CompanyBranch::factory()->create(['code' => 'OLD-HUB']);
        CompanyBranch::factory()->create(['place_code_id' => $this->rangpurDistrict->id]);

        $missing = app(DeploymentReadiness::class)->recordsMissingPlaceCodes();

        $this->assertContains($unplacedBranch->id, array_column($missing['branches'], 'id'));
        $this->assertCount(1 + CompanyBranch::query()->whereNull('place_code_id')->where('is_active', true)->where('id', '<>', $unplacedBranch->id)->count(), $missing['branches']);
        $this->assertContains('records_missing_place_codes', app(DeploymentReadiness::class)->inspect()['warnings']);
    }
}
