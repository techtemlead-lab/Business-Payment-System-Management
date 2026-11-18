<?php

namespace Tests\Feature;

use App\Domain\Staff\StaffPermissions;
use App\Domain\Support\ChatRouting;
use App\Models\CompanyBranch;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A first password that needs no email: shown once to the staff member, changed at first sign-in.
 * Also staff creation with role and branch, and owner (MD, Chairman) accounts made in the panel.
 */
class FirstPasswordAndLeadershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    /** @return array<string, mixed> */
    private function staffForm(array $over = []): array
    {
        return ['role' => 'employee', 'name' => 'Rubel Mia', 'email' => 'rubel@example.test', 'phone' => '01712345678', 'permissions' => ['orders'], ...$over];
    }

    /** The one-time first password, read from the success message (office passwords are never kept readable, SA-02). */
    private function shownFirstPassword(): string
    {
        $message = (string) session('success');
        $this->assertSame(1, preg_match('/Md[A-Z2-9]{8}\d{2}!/', $message, $match), $message);

        return $match[0];
    }

    public function test_a_new_staff_member_gets_a_first_password_shown_once_and_must_change_it(): void
    {
        $response = $this->actingAs($this->owner())->onPortal('sadmin')->post('/commands/accounts', $this->staffForm())->assertSessionHasNoErrors();

        $staff = User::query()->where('email', 'rubel@example.test')->sole();
        $this->assertTrue($staff->must_change_password);
        $password = $this->shownFirstPassword();
        $this->assertTrue(Hash::check($password, (string) $staff->password));
        $this->assertNull($staff->adminVisiblePassword(), 'An office password is never kept readable.');
        $response->assertSessionHas('success', fn (string $message): bool => str_contains($message, $password) && str_contains($message, '01712345678'));
    }

    public function test_a_person_with_a_first_password_can_only_reach_the_password_page_until_they_change_it(): void
    {
        $this->actingAs($this->owner())->onPortal('sadmin')->post('/commands/accounts', $this->staffForm());
        $staff = User::query()->where('email', 'rubel@example.test')->sole();
        $old = $this->shownFirstPassword();

        $this->actingAs($staff)->onPortal('manage')->get('/orders')->assertRedirect('/security');
        $this->actingAs($staff)->onPortal('manage')->get('/security')->assertOk();

        $this->actingAs($staff)->onPortal('manage')->post('/security/password', [
            'current_password' => $old, 'password' => 'Brand-new-pass-77', 'password_confirmation' => 'Brand-new-pass-77',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($staff->fresh()->must_change_password);
        $this->actingAs($staff->fresh())->onPortal('manage')->get('/orders')->assertOk();
    }

    public function test_the_staff_form_places_the_person_in_a_branch_with_the_chosen_role(): void
    {
        $branch = CompanyBranch::factory()->create();

        $this->actingAs($this->owner())->onPortal('sadmin')
            ->post('/commands/accounts', $this->staffForm(['role' => 'finance', 'branch_id' => $branch->id, 'permissions' => null]))
            ->assertSessionHasNoErrors();

        $staff = User::query()->where('email', 'rubel@example.test')->sole();
        $this->assertSame('finance', $staff->role);
        $this->assertSame($branch->id, $staff->active_branch_id);
    }

    public function test_the_staff_list_names_the_real_role_of_each_person(): void
    {
        User::factory()->create(['role' => 'finance', 'status' => 'active', 'name' => 'Finance Person']);

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/employees')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.employees.data.0.role_name', __('portal.staff_role_finance'))
                ->where('data.staff_role_options', fn ($options): bool => collect($options)->pluck('value')->contains('finance')));
    }

    public function test_an_owner_account_is_made_only_with_an_explicit_confirmation(): void
    {
        $owner = $this->owner();
        $form = $this->staffForm(['role' => 'md', 'name' => 'New MD', 'email' => 'md@example.test', 'phone' => '01712345679', 'permissions' => null]);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', $form)->assertSessionHasErrors('confirm_leadership');
        $this->assertDatabaseMissing('users', ['email' => 'md@example.test']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', [...$form, 'confirm_leadership' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('md', User::query()->where('email', 'md@example.test')->value('role'));
    }

    public function test_a_super_admin_is_never_made_in_the_panel(): void
    {
        $this->actingAs($this->owner())->onPortal('sadmin')
            ->post('/commands/accounts', $this->staffForm(['role' => 'super_admin', 'confirm_leadership' => '1', 'permissions' => null]))
            ->assertSessionHasErrors('role');
    }

    public function test_moving_the_md_into_a_staff_role_needs_a_yes_and_putting_back_restores_the_default(): void
    {
        $owner = $this->owner();
        $md = User::factory()->create(['role' => 'md', 'status' => 'active', 'profile' => ['action_permissions' => ['orders' => ['view' => true]]]]);
        $permissions = app(StaffPermissions::class);

        $version = $permissions->version($md);
        $this->expectValidation(fn () => $permissions->assign($owner, $md->id, ['orders' => ['view' => true]], $version, 'x', 'finance'));
        $this->assertSame('md', $md->fresh()->role);

        $permissions->assign($owner, $md->id, ['orders' => ['view' => true]], $version, 'x', 'finance', true);
        $this->assertSame('finance', $md->fresh()->role);

        $staff = $md->fresh();
        $permissions->assign($owner, $md->id, [], $permissions->version($staff), 'back', 'md', true);
        $this->assertSame('md', $md->fresh()->role);
        $this->assertArrayNotHasKey('action_permissions', $md->fresh()->profile ?? []);
    }

    public function test_the_owner_resets_an_owner_account_to_its_default_rights(): void
    {
        $md = User::factory()->create(['role' => 'md', 'status' => 'active', 'profile' => ['action_permissions' => ['finance' => ['view' => true]]]]);

        $this->actingAs($this->owner())->onPortal('sadmin')->post('/commands/accounts/'.$md->id.'/leadership-reset')->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('action_permissions', $md->fresh()->profile ?? []);
    }

    public function test_owner_accounts_are_not_offered_in_the_staff_access_search(): void
    {
        User::factory()->create(['role' => 'md', 'status' => 'active', 'name' => 'The MD']);
        User::factory()->create(['role' => 'finance', 'status' => 'active', 'name' => 'A Finance']);

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/operations/access-management')->assertOk()
            ->assertInertia(fn ($page) => $page->where('staffAccess', fn ($rows): bool => collect($rows)->pluck('role')->doesntContain('md') && collect($rows)->pluck('role')->contains('finance')));
    }

    private function expectValidation(callable $call): void
    {
        try {
            $call();
            $this->fail('A validation error was expected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }
    }

    public function test_an_approved_partner_gets_a_first_password_and_must_change_it(): void
    {
        $division = PlaceCode::factory()->division()->create();
        $district = PlaceCode::factory()->district($division)->create();
        $upazila = PlaceCode::factory()->upazila($district)->create();
        $union = PlaceCode::factory()->union($upazila)->create(['code' => 'MNHC100444']);
        $this->onPortal('public')->post('/partnership/apply', [
            'name' => 'Salam', 'email' => 'salam@example.test', 'phone' => '01711000009', 'dob_day' => 1, 'dob_month' => 1, 'dob_year' => 1985,
            'role' => 'sub_dealer', 'division' => 'x', 'district' => 'y',
        ]);
        $id = DB::table('dealer_applications')->value('id');

        $response = $this->actingAs($this->owner())->onPortal('sadmin')
            ->post('/commands/operations/staff/applications/'.$id.'/review', ['decision' => 'approved', 'place_code' => $union->code, 'reason' => 'ok']);

        $partner = User::query()->where('email', 'salam@example.test')->sole();
        $this->assertTrue($partner->must_change_password);
        $response->assertSessionHas('success', fn (string $message): bool => str_contains($message, (string) $partner->adminVisiblePassword()));
        $this->actingAs($partner)->onPortal('subdealer')->get('/')->assertRedirect('/security');
    }

    public function test_a_custom_role_shows_its_saved_name_in_the_staff_list_and_the_access_search(): void
    {
        $owner = $this->owner();
        DB::table('staff_roles')->insert(['key' => 'custom_1atnc0onzuiu', 'name' => 'Verify Support Desk Only', 'permissions' => json_encode(['support_desk' => ['view' => true]]), 'version' => 1, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        User::factory()->create(['role' => 'custom_1atnc0onzuiu', 'status' => 'active', 'name' => 'Desk Person']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/employees')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.employees.data.0.role_name', 'Verify Support Desk Only')
                ->where('data.staff_role_options', fn ($options): bool => collect($options)->contains(fn ($option): bool => $option['label'] === 'Verify Support Desk Only')));
    }

    public function test_the_chat_answerer_list_shows_the_saved_name_of_a_custom_role(): void
    {
        $owner = $this->owner();
        DB::table('staff_roles')->insert(['key' => 'custom_1atnc0onzuiu', 'name' => 'Verify Support Desk Only', 'permissions' => json_encode([]), 'version' => 1, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        User::factory()->create(['role' => 'custom_1atnc0onzuiu', 'status' => 'active', 'name' => 'Desk Person']);

        $rows = app(ChatRouting::class)->ownerPanel();

        $this->assertSame('Verify Support Desk Only', collect($rows['staff'])->firstWhere('name', 'Desk Person')['role_name']);
    }
}
