<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** CR-18: the office dashboard's buttons and its one tabbed table. */
class OfficeDashboardTabsTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $buyer, string $number, string $paid = '0.00'): Order
    {
        return Order::create([
            'number' => $number,
            'user_id' => $buyer->id,
            'status' => 'submitted',
            'total' => '500.00',
            'paid' => $paid,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ])->fresh();
    }

    /** @return array<string, mixed>|null */
    private function tab(User $viewer, string $query = ''): ?array
    {
        $found = null;
        $this->actingAs($viewer)->onPortal($viewer->role === 'super_admin' ? 'sadmin' : 'manage')->get('/'.$query)->assertOk()->assertInertia(function (Assert $page) use (&$found): void {
            $page->loadDeferredProps(function (Assert $reload) use (&$found): void {
                $found = $reload->toArray()['props']['dash_tab'] ?? null;
            });
        });

        return $found;
    }

    public function test_each_role_gets_only_the_tabs_its_rights_open(): void
    {
        $owner = User::factory()->staff()->create();
        $accounts = User::factory()->staff('account_manager')->create();
        $inventory = User::factory()->staff('inventory')->create();
        $editor = User::factory()->staff('editor')->create();

        $tabsOf = fn (User $viewer) => $this->actingAs($viewer)->onPortal($viewer->role === 'super_admin' ? 'sadmin' : 'manage')->get('/')->assertOk()->original->getData()['page']['props']['data']['admin_home']['tabs'] ?? null;

        $this->assertSame(['orders', 'accounts', 'withdrawals', 'payments', 'applications', 'low_stock'], $tabsOf($owner));
        $this->assertSame(['orders', 'accounts', 'applications'], $tabsOf($accounts));
        $this->assertSame(['orders', 'low_stock'], $tabsOf($inventory));
        $this->assertSame([], $tabsOf($editor));
    }

    public function test_the_rows_load_after_the_page_and_every_tab_returns_its_rows_to_the_owner(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim Store']);
        $order = $this->order($dealer, 'MN-TAB-0001', '500.00');
        DB::table('payment_claims')->insert(['order_id' => $order->id, 'channel' => 'bKash', 'reference' => 'TX1', 'amount' => '500.00', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('withdrawals')->insert(['id' => (string) Str::uuid(), 'user_id' => $dealer->id, 'amount' => '900.00', 'status' => 'requested', 'channel' => 'bKash', 'destination' => 'x', 'idempotency_key' => 'k1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('dealer_applications')->insert(['id' => (string) Str::uuid(), 'submitted_by' => $owner->id, 'email' => 'new@example.com', 'data' => json_encode(['name' => 'Karim', 'phone' => '01711111111', 'district' => 'Rangpur']), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        Product::factory()->create(['name' => 'Honey Low', 'stock' => 1, 'low_stock_at' => 10, 'active' => true]);

        foreach (['orders', 'accounts', 'withdrawals', 'payments', 'applications', 'low_stock'] as $key) {
            $tab = $this->tab($owner, '?tab='.$key);
            $this->assertSame($key, $tab['tab'], $key);
            $this->assertGreaterThanOrEqual(1, $tab['total'], $key);
            $this->assertNotEmpty($tab['rows'], $key);
        }
        $orders = $this->tab($owner, '?tab=orders');
        $this->assertSame('paid', $orders['rows'][0]['payment']);
        $this->assertSame('/orders', $orders['all_href']);
    }

    public function test_a_tab_the_person_may_not_open_falls_back_to_their_first_tab(): void
    {
        $accounts = User::factory()->staff('account_manager')->create();
        $this->order(User::factory()->dealer()->create(), 'MN-FALL-0001');

        $tab = $this->tab($accounts, '?tab=withdrawals');

        $this->assertSame('orders', $tab['tab']);
    }

    public function test_staff_with_no_list_rights_get_no_table(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->assertNull($this->tab($editor, '?tab=orders'));
    }

    public function test_search_and_paging_work_inside_the_tab(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        foreach (range(1, 10) as $number) {
            $this->order($dealer, 'MN-PAGE-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT));
        }
        $this->order($dealer, 'MN-ODD-9999');

        $first = $this->tab($owner, '?tab=orders');
        $second = $this->tab($owner, '?tab=orders&tp=2');
        $found = $this->tab($owner, '?tab=orders&tq=ODD-9999');

        $this->assertSame(11, $first['total']);
        $this->assertCount(8, $first['rows']);
        $this->assertSame(2, $first['last_page']);
        $this->assertCount(3, $second['rows']);
        $this->assertSame(1, $found['total']);
        $this->assertSame('MN-ODD-9999', $found['rows'][0]['number']);
    }

    public function test_the_add_buttons_follow_the_rights(): void
    {
        $owner = User::factory()->staff()->create();
        $accounts = User::factory()->staff('account_manager')->create();

        $caps = fn (User $viewer) => collect($this->actingAs($viewer)->onPortal($viewer->role === 'super_admin' ? 'sadmin' : 'manage')->get('/')->assertOk()->original->getData()['page']['props']['auth']['capabilities']);

        $this->assertTrue($caps($owner)->contains('accounts') && $caps($owner)->contains('catalog') && $caps($owner)->contains('pricing'));
        $this->assertTrue($caps($accounts)->contains('accounts'));
        $this->assertFalse($caps($accounts)->contains('catalog'));
    }
}
