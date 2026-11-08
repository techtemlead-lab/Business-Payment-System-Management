<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchScope;
use App\Domain\Support\ChatRouting;
use App\Models\CompanyBranch;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owner's rule: every branch carries a place code, and a partner belongs to the branch on
 * their place code or on the nearest place above it. Place names are never matched.
 */
class BranchPlaceCodeCoverageTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $division;

    private PlaceCode $district;

    private PlaceCode $otherDistrict;

    private PlaceCode $upazila;

    private PlaceCode $otherUpazila;

    private PlaceCode $union;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->division = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $this->district = PlaceCode::factory()->district($this->division)->create(['name' => 'Rangpur']);
        $this->otherDistrict = PlaceCode::factory()->district($this->division)->create(['name' => 'Dinajpur']);
        $this->upazila = PlaceCode::factory()->upazila($this->district)->create(['name' => 'Pirganj']);
        $this->otherUpazila = PlaceCode::factory()->upazila($this->district)->create(['name' => 'Mithapukur']);
        $this->union = PlaceCode::factory()->union($this->upazila)->create(['name' => 'Chaitrakol']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function branchOn(PlaceCode $place, array $attributes = []): CompanyBranch
    {
        return CompanyBranch::factory()->create([...$attributes, 'place_code_id' => $place->id]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function partnerIn(PlaceCode $place, array $attributes = []): User
    {
        return User::factory()->dealer()->inPlace($place)->create($attributes);
    }

    private function branchIdOf(User $partner): ?int
    {
        return app(ChatRouting::class)->partnerBranchId($partner);
    }

    private function owner(): User
    {
        return User::factory()->staff('super_admin')->create();
    }

    /** What the Branches page's edit form sends for the head office. */
    private function headOfficeEdit(CompanyBranch $headOffice, string $placeCode): TestResponse
    {
        return $this->patch('/commands/branches/'.$headOffice->id, [
            'code' => $headOffice->code,
            'name' => $headOffice->name,
            'place_code' => $placeCode,
            'address' => $headOffice->address,
            'phone' => $headOffice->phone,
            'is_active' => true,
            'is_delivery_point' => true,
            'is_stock_hub' => true,
        ]);
    }

    public function test_branches_at_division_district_and_upazila_level_cover_the_partners_under_them(): void
    {
        $divisionBranch = $this->branchOn($this->division);
        $districtBranch = $this->branchOn($this->district);
        $upazilaBranch = $this->branchOn($this->upazila);

        $this->assertSame($upazilaBranch->id, $this->branchIdOf($this->partnerIn($this->union)));
        $this->assertSame($upazilaBranch->id, $this->branchIdOf($this->partnerIn($this->upazila)));
        $this->assertSame($districtBranch->id, $this->branchIdOf($this->partnerIn($this->otherUpazila)));
        $this->assertSame($districtBranch->id, $this->branchIdOf($this->partnerIn($this->district)));
        $this->assertSame($divisionBranch->id, $this->branchIdOf($this->partnerIn($this->otherDistrict)));
        $this->assertSame($divisionBranch->id, $this->branchIdOf($this->partnerIn($this->division)));
    }

    public function test_the_deepest_branch_wins_whichever_was_created_first(): void
    {
        $upazilaBranch = $this->branchOn($this->upazila);
        $this->branchOn($this->district);
        $this->branchOn($this->division);

        $this->assertSame($upazilaBranch->id, $this->branchIdOf($this->partnerIn($this->union)));
    }

    public function test_a_closed_branch_is_passed_over_for_the_next_one_up(): void
    {
        $this->branchOn($this->upazila, ['is_active' => false]);
        $districtBranch = $this->branchOn($this->district);

        $this->assertSame($districtBranch->id, $this->branchIdOf($this->partnerIn($this->union)));
    }

    public function test_two_districts_with_the_same_name_do_not_mix(): void
    {
        $otherDivision = PlaceCode::factory()->division()->create(['name' => 'Khulna']);
        $namesake = PlaceCode::factory()->district($otherDivision)->create(['name' => 'Rangpur']);
        $branch = $this->branchOn($this->district, ['district' => 'Rangpur']);
        $partnerInNamesake = $this->partnerIn($namesake);

        $this->assertSame('Rangpur', $partnerInNamesake->district);
        $this->assertNull($this->branchIdOf($partnerInNamesake));
        $this->assertSame($branch->id, $this->branchIdOf($this->partnerIn($this->district)));
    }

    public function test_a_branch_saved_on_the_partner_still_wins(): void
    {
        $this->branchOn($this->upazila);
        $savedBranch = $this->branchOn($this->otherDistrict);

        $this->assertSame($savedBranch->id, $this->branchIdOf($this->partnerIn($this->union, ['active_branch_id' => $savedBranch->id])));
    }

    public function test_a_partner_with_no_match_stays_unassigned_and_only_all_branch_viewers_see_them(): void
    {
        $branch = $this->branchOn($this->district, ['district' => 'Rangpur']);
        $covered = $this->partnerIn($this->upazila);
        $namedOnly = User::factory()->dealer()->create(['district' => 'Rangpur']);
        $outsideEveryBranch = $this->partnerIn($this->otherDistrict);
        $unreadablePlace = User::factory()->dealer()->create(['profile' => ['place_code_id' => 'not-a-number']]);
        $unassigned = [$namedOnly, $outsideEveryBranch, $unreadablePlace];
        foreach ($unassigned as $partner) {
            $this->assertNull($this->branchIdOf($partner));
        }

        $ids = [$covered->id, ...array_map(fn (User $partner): int => $partner->id, $unassigned)];
        $visibleTo = fn (BranchScope $scope): Collection => $scope->restrictPartners(User::query()->whereIn('id', $ids))->pluck('id');

        $this->assertSame([$covered->id], $visibleTo(new BranchScope(false, [$branch->id]))->all());
        $this->assertEqualsCanonicalizing($ids, $visibleTo(new BranchScope(true, []))->all());
    }

    public function test_creating_or_editing_a_branch_needs_a_place_code(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/branches', ['code' => 'BR-NEW', 'name' => 'New branch'])
            ->assertSessionHasErrors('place_code');
        $this->assertDatabaseMissing('company_branches', ['code' => 'BR-NEW']);

        $withoutCode = CompanyBranch::factory()->create();
        $this->actingAs($owner)->onPortal('sadmin')
            ->patch('/commands/branches/'.$withoutCode->id, ['code' => $withoutCode->code, 'name' => $withoutCode->name, 'place_code' => ''])
            ->assertSessionHasErrors('place_code');
        $this->assertNull($withoutCode->refresh()->place_code_id);
    }

    public function test_head_office_can_be_given_its_place_code_on_the_branches_page(): void
    {
        $owner = $this->owner();
        $headOffice = CompanyBranch::query()->where('is_head_office', true)->firstOrFail();
        $this->assertNull($headOffice->place_code_id);
        $this->assertNotNull($headOffice->notes);

        $this->actingAs($owner)->onPortal('sadmin')->get('/branches')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.head_office_without_place_code.id', $headOffice->id)
            ->where('data.can_edit_branches', true));

        $this->actingAs($owner)->onPortal('sadmin');
        $this->headOfficeEdit($headOffice, $this->upazila->code)->assertRedirect()->assertSessionHasNoErrors();

        $saved = $headOffice->fresh();
        $this->assertSame($this->upazila->id, $saved->place_code_id);
        $this->assertSame('Pirganj', $saved->upazila);
        $this->assertTrue($saved->is_head_office);
        $this->assertSame($headOffice->notes, $saved->notes, 'Adding the place code must not wipe the other saved details.');

        $this->actingAs($owner)->onPortal('sadmin')->get('/branches')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.head_office_without_place_code', null));
        $this->assertSame($headOffice->id, $this->branchIdOf($this->partnerIn($this->union)));
    }

    public function test_inventory_staff_can_also_give_the_head_office_its_place_code(): void
    {
        $headOffice = CompanyBranch::query()->where('is_head_office', true)->firstOrFail();

        $this->actingAs(User::factory()->staff('inventory')->create())->onPortal('manage');
        $this->headOfficeEdit($headOffice, $this->district->code)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($this->district->id, $headOffice->fresh()->place_code_id);
    }

    public function test_the_head_office_prompt_offers_no_button_to_those_who_cannot_edit_branches(): void
    {
        $this->actingAs(User::factory()->staff('md')->create())->onPortal('sadmin')->get('/branches')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->whereNot('data.head_office_without_place_code', null)
            ->where('data.can_edit_branches', false));
    }

    public function test_the_branches_page_counts_the_partners_of_each_branch(): void
    {
        $districtBranch = $this->branchOn($this->district);
        $upazilaBranch = $this->branchOn($this->upazila);
        $emptyBranch = $this->branchOn($this->otherDistrict);
        $this->partnerIn($this->union);
        $this->partnerIn($this->upazila);
        $this->partnerIn($this->otherUpazila);
        User::factory()->staff('employee')->create(['active_branch_id' => $districtBranch->id]);

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/branches')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.branches.data', function ($rows) use ($districtBranch, $upazilaBranch, $emptyBranch): bool {
                $counts = collect($rows)->pluck('partners_count', 'id');

                return $counts[$districtBranch->id] === 1 && $counts[$upazilaBranch->id] === 2 && $counts[$emptyBranch->id] === 0;
            }));
    }
}
