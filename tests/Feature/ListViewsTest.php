<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Saved filters and hidden columns belong to one person and only hold allowed filter names.
 */
class ListViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_filters_are_per_user(): void
    {
        $first = User::factory()->staff()->create();
        $second = User::factory()->staff()->create();

        $this->actingAs($first)->onPortal('sadmin')
            ->postJson('/commands/list-views/orders', ['name' => 'Waiting today', 'filters' => ['status' => 'submitted', 'period' => 'today']])
            ->assertOk()
            ->assertJsonPath('data.views.0.name', 'Waiting today')
            ->assertJsonPath('data.views.0.filters.status', 'submitted');

        $this->actingAs($second)->onPortal('sadmin')->getJson('/commands/list-views/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data.views');
        $this->actingAs($first)->onPortal('sadmin')->getJson('/commands/list-views/orders')
            ->assertJsonCount(1, 'data.views');
    }

    public function test_one_person_cannot_delete_another_persons_saved_filter(): void
    {
        $first = User::factory()->staff()->create();
        $second = User::factory()->staff()->create();
        $this->actingAs($first)->onPortal('sadmin')
            ->postJson('/commands/list-views/orders', ['name' => 'Mine', 'filters' => ['status' => 'held']])->assertOk();
        $viewId = (int) DB::table('saved_list_filters')->value('id');

        $this->actingAs($second)->onPortal('sadmin')->deleteJson('/commands/list-views/orders/'.$viewId)->assertOk();

        $this->assertDatabaseHas('saved_list_filters', ['id' => $viewId, 'user_id' => $first->id]);
    }

    public function test_hidden_columns_are_per_user_and_per_list(): void
    {
        $first = User::factory()->staff()->create();
        $second = User::factory()->staff()->create();

        $this->actingAs($first)->onPortal('sadmin')
            ->putJson('/commands/list-views/accounts/columns', ['hidden' => ['district', 'last_order_at']])
            ->assertOk()
            ->assertJsonPath('data.hidden', ['district', 'last_order_at']);

        $this->actingAs($second)->onPortal('sadmin')->getJson('/commands/list-views/accounts')->assertJsonPath('data.hidden', []);
        $this->actingAs($first)->onPortal('sadmin')->getJson('/commands/list-views/sub-dealers')->assertJsonPath('data.hidden', []);
    }

    public function test_only_known_filter_names_are_kept(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')
            ->postJson('/commands/list-views/orders', ['name' => 'Mixed', 'filters' => ['status' => 'held', 'branch_id' => '9', 'evil' => 'x']])
            ->assertOk();

        $this->assertSame(['status' => 'held'], json_decode((string) DB::table('saved_list_filters')->value('filters'), true));
    }

    public function test_the_chosen_sort_is_saved_with_the_filters(): void
    {
        $owner = User::factory()->staff()->create();

        foreach (['orders', 'accounts'] as $list) {
            $this->actingAs($owner)->onPortal('sadmin')
                ->postJson("/commands/list-views/{$list}", ['name' => 'Biggest first', 'filters' => ['status' => 'held', 'sort' => 'total', 'dir' => 'desc']])
                ->assertOk()
                ->assertJsonPath('data.views.0.filters.status', 'held')
                ->assertJsonPath('data.views.0.filters.sort', 'total')
                ->assertJsonPath('data.views.0.filters.dir', 'desc');
        }
    }

    public function test_a_filter_with_nothing_chosen_is_refused(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')
            ->postJson('/commands/list-views/orders', ['name' => 'Empty', 'filters' => ['q' => ' ']])
            ->assertUnprocessable();
    }

    public function test_the_list_needs_the_same_permission_as_the_page(): void
    {
        $catalogOnly = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['catalog']]]);
        $ordersOnly = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['orders']]]);

        $this->actingAs($catalogOnly)->onPortal('manage')->getJson('/commands/list-views/orders')->assertForbidden();
        $this->actingAs($ordersOnly)->onPortal('manage')->getJson('/commands/list-views/accounts')->assertForbidden();
        $this->actingAs($ordersOnly)->onPortal('manage')->getJson('/commands/list-views/orders')->assertOk();
    }

    public function test_staff_with_a_limited_role_can_save_their_own_filters(): void
    {
        $ordersOnly = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['orders']]]);

        $this->actingAs($ordersOnly)->onPortal('manage')
            ->postJson('/commands/list-views/deliveries', ['name' => 'Late', 'filters' => ['status' => 'overdue_delivery']])
            ->assertOk()
            ->assertJsonPath('data.views.0.name', 'Late');
    }

    public function test_partners_cannot_use_saved_filters(): void
    {
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($dealer)->onPortal('dealer')->getJson('/commands/list-views/orders')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')
            ->postJson('/commands/list-views/orders', ['name' => 'No', 'filters' => ['status' => 'held']])
            ->assertForbidden();
    }
}
