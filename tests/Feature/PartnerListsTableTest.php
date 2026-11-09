<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The dealer, sub-dealer and application lists: the database sorts and filters them, so the
 * order and the totals are right across every page and not only for the rows on screen.
 */
class PartnerListsTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_list_sorts_by_name_across_every_page(): void
    {
        $owner = User::factory()->staff()->create();
        foreach (range(1, 22) as $number) {
            User::factory()->dealer()->create(['name' => sprintf('Dealer %02d', $number), 'status' => 'active']);
        }

        $data = $this->data($owner, '/accounts?sort=name&dir=desc');

        $this->assertSame('Dealer 22', $data['accounts']['data'][0]['name']);
        $this->assertCount(20, $data['accounts']['data']);
        $this->assertSame(22, $data['accounts']['total']);
        $this->assertSame(22, $data['accounts_table']['totals']['count']);
        $this->assertSame('name', $data['accounts_table']['state']['sort']);
        $this->assertSame('desc', $data['accounts_table']['state']['dir']);
        $this->assertContains('month_purchase', $data['accounts_table']['sortable']);
    }

    public function test_the_newest_partner_comes_first_when_nothing_is_chosen(): void
    {
        $owner = User::factory()->staff()->create();
        $older = User::factory()->dealer()->create(['name' => 'Older', 'status' => 'active']);
        $newer = User::factory()->dealer()->create(['name' => 'Newer', 'status' => 'active']);

        $this->assertSame([$newer->id, $older->id], collect($this->data($owner, '/accounts?sort=not-a-column')['accounts']['data'])->pluck('id')->all());
    }

    public function test_the_list_sorts_by_what_a_partner_bought_this_month(): void
    {
        $owner = User::factory()->staff()->create();
        $small = User::factory()->dealer()->create(['name' => 'Small', 'status' => 'active']);
        $large = User::factory()->dealer()->create(['name' => 'Large', 'status' => 'active']);
        $none = User::factory()->dealer()->create(['name' => 'None', 'status' => 'active']);
        $this->order($small, '100.00');
        $this->order($large, '900.00');

        $ids = collect($this->data($owner, '/accounts?sort=month_purchase&dir=desc')['accounts']['data'])->pluck('id')->all();

        $this->assertSame([$large->id, $small->id, $none->id], $ids);
    }

    public function test_rows_carry_everything_the_edit_form_and_the_side_panel_read(): void
    {
        $owner = User::factory()->staff()->create();
        $sponsor = User::factory()->dealer()->create(['name' => 'Sponsor Dealer', 'dealer_code' => 'SP-1', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim', 'status' => 'active', 'sponsor_id' => $sponsor->id, 'phone' => '01712345678', 'profile' => ['shop_name' => 'Rahim Store', 'place_code' => 'MNHC-200', 'date_of_birth' => '1990-01-01']]);

        $row = collect($this->data($owner, '/accounts?q=Rahim')['accounts']['data'])->firstWhere('id', $dealer->id);

        $this->assertSame('Rahim Store', $row['profile']['shop_name']);
        $this->assertSame('MNHC-200', $row['profile']['place_code']);
        $this->assertArrayNotHasKey('date_of_birth', $row['profile'], 'Identity details stay out of the page, as before.');
        $this->assertSame('Sponsor Dealer', $row['sponsor']['name']);
        $this->assertSame('01712345678', $row['phone']);
        $this->assertSame('dealer', $row['current_rank']);
    }

    public function test_closed_partners_stay_out_of_every_view_but_their_own(): void
    {
        $owner = User::factory()->staff()->create();
        $open = User::factory()->dealer()->create(['name' => 'Open', 'status' => 'active']);
        $closed = User::factory()->dealer()->create(['name' => 'Closed', 'status' => 'active', 'closed_at' => now()]);
        $suspended = User::factory()->dealer()->create(['name' => 'Suspended', 'status' => 'suspended']);

        $everyone = $this->data($owner, '/accounts');
        $this->assertSame([$suspended->id, $open->id], collect($everyone['accounts']['data'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['closed' => 1, 'active' => 1, 'suspended' => 1], $everyone['account_status_counts']);

        $closedView = $this->data($owner, '/accounts?status=closed');
        $this->assertSame([$closed->id], collect($closedView['accounts']['data'])->pluck('id')->all());
        $this->assertSame([], $closedView['accounts_table']['state']['filters'], 'The Closed view is its own switch, not a filter a person can clear.');

        $suspendedOnly = $this->data($owner, '/accounts?status=suspended');
        $this->assertSame([$suspended->id], collect($suspendedOnly['accounts']['data'])->pluck('id')->all());
        $this->assertSame(['status' => 'suspended'], $suspendedOnly['accounts_table']['state']['filters']);
    }

    public function test_a_district_from_another_division_is_dropped_so_a_changed_division_never_shows_an_empty_list(): void
    {
        $owner = User::factory()->staff()->create();
        $dhaka = PlaceCode::factory()->division()->create(['name' => 'Dhaka']);
        $gazipur = PlaceCode::factory()->district($dhaka)->create(['name' => 'Gazipur']);
        $khulna = PlaceCode::factory()->division()->create(['name' => 'Khulna']);
        $jessore = PlaceCode::factory()->district($khulna)->create(['name' => 'Jessore']);
        $inGazipur = User::factory()->dealer()->inPlace($gazipur)->create(['name' => 'Gazipur Dealer', 'status' => 'active']);
        User::factory()->dealer()->inPlace($jessore)->create(['name' => 'Jessore Dealer', 'status' => 'active']);

        $data = $this->data($owner, '/accounts?division='.$dhaka->id.'&district='.$jessore->id);

        $this->assertSame([$inGazipur->id], collect($data['accounts']['data'])->pluck('id')->all());
        $this->assertSame(['division' => (string) $dhaka->id], $data['accounts_table']['state']['filters']);
    }

    public function test_the_chosen_filters_are_reported_back_to_the_toolbar(): void
    {
        $owner = User::factory()->staff()->create();
        User::factory()->dealer()->create(['name' => 'Rahim', 'status' => 'active']);

        $state = $this->data($owner, '/accounts?q=Rahim&rank=mo&no_order_days=60')['accounts_table']['state'];

        $this->assertSame('Rahim', $state['q']);
        $this->assertSame(['rank' => 'mo', 'no_order_days' => '60'], $state['filters']);
        $this->assertSame('', $state['period'], 'The partner list has no date view.');
    }

    public function test_applications_filter_by_source_and_sort_by_name_across_every_page(): void
    {
        $owner = User::factory()->staff()->create();
        foreach (range(1, 22) as $number) {
            $this->application(sprintf('Applicant %02d', $number), 'public', 'pending');
        }
        $this->application('Field Applicant', 'staff', 'pending');
        $this->application('Rejected Applicant', 'staff', 'rejected');

        $byName = $this->data($owner, '/partnerships?sort=name&dir=desc');
        $this->assertSame('Rejected Applicant', $byName['applications']['data'][0]['name']);
        $this->assertSame(24, $byName['applications']['total']);
        $this->assertSame(24, $byName['applications_table']['totals']['count']);

        $fromStaff = $this->data($owner, '/partnerships?source=staff');
        $this->assertEqualsCanonicalizing(['Rejected Applicant', 'Field Applicant'], collect($fromStaff['applications']['data'])->pluck('name')->all());
        $this->assertSame(['source' => 'staff'], $fromStaff['applications_table']['state']['filters']);

        $rejected = $this->data($owner, '/partnerships?status=rejected');
        $this->assertSame(1, $rejected['applications']['total']);
        $this->assertEquals(['pending' => 23, 'approved' => 0, 'rejected' => 1, 'total' => 24], $rejected['application_counts']);
    }

    public function test_application_rows_keep_every_field_the_review_panel_reads(): void
    {
        $owner = User::factory()->staff()->create();
        $this->application('Karim Traders', 'public', 'pending', ['division' => 'Dhaka', 'district' => 'Gazipur', 'upazila' => 'Sadar', 'place_code' => 'MNHC-100001', 'sponsor_code' => 'SP-1', 'message' => 'Please check']);

        $row = $this->data($owner, '/partnerships')['applications']['data'][0];

        $this->assertSame('Karim Traders', $row['name']);
        $this->assertSame('01712345678', $row['phone']);
        $this->assertSame('MNHC-100001', $row['place_code']);
        $this->assertSame('SP-1', $row['sponsor_code']);
        $this->assertSame('Please check', $row['message']);
        $this->assertSame('public', $row['source']);
        $this->assertSame('dealer', $row['role']);
        $this->assertArrayHasKey('submitted_by', $row);
    }

    public function test_an_unknown_source_is_ignored(): void
    {
        $owner = User::factory()->staff()->create();
        $this->application('Karim', 'public', 'pending');

        $data = $this->data($owner, '/partnerships?source=not-a-source');

        $this->assertSame(1, $data['applications']['total']);
        $this->assertSame([], $data['applications_table']['state']['filters']);
    }

    /** @return array<string, mixed> */
    private function data(User $staff, string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($staff)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json('props.data');
    }

    private function order(User $buyer, string $total): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => $total,
            'paid' => '0.00',
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->addHours(2)->utc()]);

        return $order;
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function application(string $name, string $source, string $status, array $extra = []): void
    {
        DB::table('dealer_applications')->insert([
            'id' => (string) Str::uuid(),
            'submitted_by' => null,
            'email' => Str::slug($name).'@example.test',
            'data' => json_encode(['name' => $name, 'phone' => '01712345678', 'role' => 'dealer', ...$extra], JSON_THROW_ON_ERROR),
            'status' => $status,
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
