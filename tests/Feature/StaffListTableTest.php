<?php

namespace Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The staff list is one table: search, role, branch and status filters and column sort all run on
 * the server, so they cover every page. The old addresses and the old page data keep working.
 */
class StaffListTableTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff()->create();
    }

    public function test_the_old_page_data_is_still_sent_with_the_table_state_beside_it(): void
    {
        $finance = User::factory()->staff('finance')->create(['name' => 'Rahima Finance']);

        $this->visit('/employees')->assertInertia(fn (Assert $page) => $page
            ->where('data.employees.data.0.id', $finance->id)
            ->where('data.employees.data.0.name', 'Rahima Finance')
            ->where('data.employees.data.0.role', 'finance')
            ->has('data.employees.data.0.effective_permissions')
            ->where('data.employees.total', 1)
            ->where('data.employees.table.totals.count', 1)
            ->where('data.employees.table.state.q', '')
            ->where('data.employees.table.state.period', ''));
    }

    public function test_the_search_finds_a_person_by_name_email_or_phone_on_every_page(): void
    {
        User::factory()->staff('finance')->count(21)->create();
        $wanted = User::factory()->staff('support')->create(['name' => 'Salma Unique', 'email' => 'salma@example.test', 'phone' => '01700000099']);
        User::query()->whereKey($wanted->id)->update(['created_at' => now()->subYear()]);

        foreach (['Salma', 'salma@example', '01700000099'] as $words) {
            $this->visit('/employees?q='.$words)->assertInertia(fn (Assert $page) => $page
                ->where('data.employees.total', 1)
                ->where('data.employees.data.0.id', $wanted->id)
                ->where('data.employees.table.state.q', $words));
        }
    }

    public function test_the_status_address_used_before_still_filters(): void
    {
        $off = User::factory()->staff('finance')->create(['status' => 'suspended']);
        User::factory()->staff('finance')->create();

        $this->visit('/employees?status=suspended')->assertInertia(fn (Assert $page) => $page
            ->where('data.employees.total', 1)
            ->where('data.employees.data.0.id', $off->id)
            ->where('data.employees.table.state.filters.status', 'suspended'));
    }

    public function test_the_role_filter_offers_built_in_and_custom_roles_but_never_the_owners(): void
    {
        $support = User::factory()->staff('support')->create();
        User::factory()->staff('finance')->create();

        $response = $this->visit('/employees?role=support')->assertInertia(fn (Assert $page) => $page
            ->where('data.employees.total', 1)
            ->where('data.employees.data.0.id', $support->id));

        $filters = collect($response->viewData('page')['props']['data']['employees']['table']['filters'])->keyBy('key');
        $roles = array_column($filters['role']['options'], 'value');
        $this->assertContains('support', $roles);
        $this->assertContains('employee', $roles);
        $this->assertEmpty(array_intersect(['super_admin', 'md', 'chairman'], $roles));
    }

    public function test_the_branch_filter_uses_its_own_name_and_leaves_the_top_bar_branch_alone(): void
    {
        $dhaka = CompanyBranch::factory()->create(['name' => 'Dhaka', 'code' => 'DHK-09']);
        $rajshahi = CompanyBranch::factory()->create(['name' => 'Rajshahi', 'code' => 'RAJ-09']);
        $inDhaka = User::factory()->staff('finance')->create(['active_branch_id' => $dhaka->id]);
        User::factory()->staff('finance')->create(['active_branch_id' => $rajshahi->id]);

        $this->visit('/employees?all_branches=1&staff_branch='.$dhaka->id)->assertInertia(fn (Assert $page) => $page
            ->where('data.employees.total', 1)
            ->where('data.employees.data.0.id', $inDhaka->id)
            ->where('data.employees.data.0.branch_name', 'Dhaka'));
    }

    public function test_the_name_column_sorts_across_every_page(): void
    {
        User::factory()->staff('finance')->count(21)->create();
        $first = User::factory()->staff('finance')->create(['name' => '0 First By Name']);
        User::query()->whereKey($first->id)->update(['created_at' => now()->subYear()]);

        $this->visit('/employees?sort=name&dir=asc')->assertInertia(fn (Assert $page) => $page
            ->where('data.employees.data.0.id', $first->id)
            ->where('data.employees.table.state.sort', 'name')
            ->where('data.employees.table.state.dir', 'asc'));
    }

    public function test_a_page_past_the_end_shows_the_last_page(): void
    {
        User::factory()->staff('finance')->count(3)->create();

        $this->visit('/employees?page=9')->assertInertia(fn (Assert $page) => $page
            ->where('data.employees.current_page', 1)
            ->where('data.employees.total', 3));
    }

    public function test_the_three_cards_do_not_follow_the_search(): void
    {
        User::factory()->staff('finance')->count(2)->create();
        User::factory()->staff('finance')->create(['status' => 'suspended']);

        $this->visit('/employees?q=nobody-has-this-name')->assertInertia(fn (Assert $page) => $page
            ->where('data.employees.total', 0)
            ->where('data.employee_stats.total', 3)
            ->where('data.employee_stats.active', 2)
            ->where('data.employee_stats.suspended', 1));
    }

    private function visit(string $address): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->get($address)->assertOk();
    }
}
