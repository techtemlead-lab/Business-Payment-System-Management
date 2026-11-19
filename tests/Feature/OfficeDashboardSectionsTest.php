<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** CR-18: the dashboard's summary sections show only to the people who may open the page behind each. */
class OfficeDashboardSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function visit(User $viewer): TestResponse
    {
        return $this->actingAs($viewer)->onPortal($viewer->role === 'super_admin' ? 'sadmin' : 'manage')->get('/')->assertOk();
    }

    /** @return list<string> */
    private function sectionsOf(User $viewer): array
    {
        return $this->visit($viewer)->original->getData()['page']['props']['data']['admin_home']['sections'];
    }

    /** @return array<string, mixed> */
    private function loaded(User $viewer): array
    {
        $found = [];
        $this->visit($viewer)->assertInertia(function (Assert $page) use (&$found): void {
            $page->missing('dash_sections')->loadDeferredProps(function (Assert $reload) use (&$found): void {
                $found = $reload->toArray()['props']['dash_sections'] ?? [];
            });
        });

        return $found;
    }

    private function order(User $buyer, string $number): Order
    {
        return Order::create([
            'number' => $number,
            'user_id' => $buyer->id,
            'status' => 'submitted',
            'total' => '500.00',
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    public function test_each_role_is_offered_only_the_sections_its_rights_open(): void
    {
        $withActivity = Schema::hasTable('partner_activity') ? ['activity'] : [];

        $this->assertSame(['money', 'site', 'business', 'events', ...$withActivity], $this->sectionsOf(User::factory()->staff()->create()));
        $this->assertSame(['money', 'business', 'events'], $this->sectionsOf(User::factory()->staff('finance')->create()));
        $this->assertSame(['business'], $this->sectionsOf(User::factory()->staff('account_manager')->create()));
        $this->assertSame(['business'], $this->sectionsOf(User::factory()->staff('inventory')->create()));
        $this->assertSame([], $this->sectionsOf(User::factory()->staff('editor')->create()));
    }

    public function test_the_owner_gets_every_section_filled_after_the_page_shows(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create(['district' => 'Rangpur', 'division' => 'Rangpur']);
        $this->order($dealer, 'MN-SEC-0001');
        $this->order($dealer, 'MN-SEC-0002');

        $sections = $this->loaded($owner);

        $this->assertArrayHasKey('money', $sections);
        $this->assertNotNull($sections['money']['funds']);
        $this->assertNotNull($sections['money']['sales']);
        $this->assertNotNull($sections['money']['waiting']);
        $this->assertSame(2, $sections['business']['today']['orders']);
        $this->assertSame('1000.00', $sections['business']['today']['amount']);
        $this->assertSame('Rangpur', $sections['business']['districts']['today'][0]['name']);
        $this->assertSame(2, $sections['business']['districts']['today'][0]['orders']);
        $this->assertArrayHasKey('active_now', $sections['site']);
        $this->assertIsArray($sections['events']['rows']);
    }

    public function test_finance_staff_get_money_and_events_but_not_the_website_numbers(): void
    {
        $finance = User::factory()->staff('finance')->create();

        $sections = $this->loaded($finance);

        $this->assertArrayHasKey('money', $sections);
        $this->assertArrayHasKey('events', $sections);
        $this->assertArrayNotHasKey('site', $sections);
        $this->assertArrayNotHasKey('activity', $sections);
    }

    public function test_order_staff_get_only_the_products_and_orders_summary(): void
    {
        $manager = User::factory()->staff('account_manager')->create();

        $sections = $this->loaded($manager);

        $this->assertSame(['business'], array_keys($sections));
    }

    public function test_editor_staff_get_no_summary_at_all(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->assertSame([], $this->loaded($editor));
        $this->assertArrayNotHasKey('dash_sections', $this->visit($editor)->original->getData()['page']['props']);
    }

    public function test_partner_activity_shows_when_the_table_exists_and_counts_online_and_today(): void
    {
        $dropAfter = ! Schema::hasTable('partner_activity');
        if ($dropAfter) {
            Schema::create('partner_activity', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->timestamp('last_seen_at')->index();
            });
        }
        $owner = User::factory()->staff()->create();
        $online = User::factory()->dealer()->create();
        $earlier = User::factory()->subDealer()->create();
        DB::table('partner_activity')->insert([
            ['user_id' => $online->id, 'last_seen_at' => now()->subMinute()],
            ['user_id' => $earlier->id, 'last_seen_at' => now()->subMinutes(30)],
        ]);

        $sections = $this->loaded($owner);

        $this->assertSame(1, $sections['activity']['online']);
        $this->assertGreaterThanOrEqual(1, $sections['activity']['today']);
        $this->assertArrayNotHasKey('activity', $this->loaded(User::factory()->staff('finance')->create()));
    }
}
