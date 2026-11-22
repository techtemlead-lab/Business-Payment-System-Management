<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The shop name is optional for dealers and sub-dealers, kept as `shop_name`, and can be changed later. */
class SimpleNewAccountShopNameTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $upazila;

    private PlaceCode $union;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $division = PlaceCode::factory()->division()->create();
        $district = PlaceCode::factory()->district($division)->create();
        $this->upazila = PlaceCode::factory()->upazila($district)->create(['code' => 'MNHC100555']);
        $this->union = PlaceCode::factory()->union($this->upazila)->create(['code' => 'MNHC100556']);
        User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'MNHC777', 'refer_code' => 'MNHC777']);
    }

    /** @return array<string, mixed> */
    private function form(string $role, string $code, array $over = []): array
    {
        return [
            'role' => $role, 'identity_type' => 'nid', 'nid' => $role === 'dealer' ? '19851234567890123' : '19851234567890124', 'date_of_birth' => '1985-03-03',
            'place_code' => $code, 'name' => 'Md. Rafiq', 'name_bn' => 'মোঃ রফিক', 'father_name' => 'A', 'mother_name' => 'B',
            'phone' => '0171234'.($role === 'dealer' ? '5678' : '5679'), 'email' => $role.'@example.test', 'reference_code' => 'MNHC777', 'status' => 'active', 'reason' => 'Checked',
            ...$over,
        ];
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    public function test_a_dealer_and_a_sub_dealer_can_be_added_without_a_shop_name(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/commands/accounts', $this->form('dealer', $this->upazila->code))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/commands/accounts', $this->form('sub_dealer', $this->union->code))->assertSessionHasNoErrors();

        $this->assertSame(3, User::query()->whereIn('role', ['dealer', 'sub_dealer'])->count());
    }

    public function test_the_shop_name_is_saved_for_both_types_when_given(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/commands/accounts', $this->form('dealer', $this->upazila->code, ['shop_name' => 'Madina Care Point']))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/commands/accounts', $this->form('sub_dealer', $this->union->code, ['shop_name' => 'Rahim Pharmacy']))->assertSessionHasNoErrors();

        $this->assertSame('Madina Care Point', User::query()->where('email', 'dealer@example.test')->firstOrFail()->profile['shop_name']);
        $this->assertSame('Rahim Pharmacy', User::query()->where('email', 'sub_dealer@example.test')->firstOrFail()->profile['shop_name']);
    }

    public function test_the_shop_name_can_be_changed_later_by_staff(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/commands/accounts', $this->form('dealer', $this->upazila->code, ['shop_name' => 'Old Name']));
        $dealer = User::query()->where('email', 'dealer@example.test')->firstOrFail();

        $this->actingAs($owner)->patch('/commands/accounts/'.$dealer->id, ['profile' => ['shop_name' => 'New Name', 'pharmacy' => 'New Name'], 'reason' => 'Corrected'])->assertSessionHasNoErrors();

        $this->assertSame('New Name', $dealer->fresh()->profile['shop_name']);
    }

    public function test_the_public_application_takes_an_optional_shop_name_that_follows_the_account(): void
    {
        $this->onPortal('public')->post('/partnership/apply', [
            'name' => 'Shirin', 'email' => 'shirin@example.test', 'phone' => '01711000077', 'dob_day' => 1, 'dob_month' => 1, 'dob_year' => 1985,
            'role' => 'sub_dealer', 'division' => 'x', 'district' => 'y', 'shop_name' => 'Shirin Store',
        ])->assertSessionHasNoErrors();
        $this->onPortal('public')->post('/partnership/apply', [
            'name' => 'No Shop', 'email' => 'noshop@example.test', 'phone' => '01711000078', 'dob_day' => 1, 'dob_month' => 1, 'dob_year' => 1985,
            'role' => 'sub_dealer', 'division' => 'x', 'district' => 'y',
        ])->assertSessionHasNoErrors();

        $id = DB::table('dealer_applications')->where('email', 'shirin@example.test')->value('id');
        $this->actingAs($this->owner())->onPortal('sadmin')->post('/commands/operations/staff/applications/'.$id.'/review', ['decision' => 'approved', 'place_code' => $this->union->code, 'reason' => 'ok'])->assertSessionHasNoErrors();

        $this->assertSame('Shirin Store', User::query()->where('email', 'shirin@example.test')->firstOrFail()->profile['shop_name']);
        $this->assertSame(2, DB::table('dealer_applications')->count());
    }

    public function test_the_public_form_asks_for_the_shop_name_as_optional(): void
    {
        $this->onPortal('public')->get('/partnership')->assertOk()->assertSee('name="shop_name"', false);
    }
}
