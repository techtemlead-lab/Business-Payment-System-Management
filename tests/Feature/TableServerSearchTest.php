<?php

namespace Tests\Feature;

use App\Domain\Commissions\RetainedFundLedger;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The search box and status filter on paged lists must look at every record, not only the page on screen.
 */
class TableServerSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff()->create();
    }

    public function test_enquiry_search_and_status_cover_every_page(): void
    {
        foreach (range(1, 25) as $index) {
            $this->enquiry('Customer '.$index, '0171000'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), 'new');
        }
        $this->enquiry('Rahima Begum', '01899999999', 'contacted', now()->subYear());
        $this->enquiry('100% Natural_Shop', '01888888888', 'closed', now()->subYear());

        $byName = $this->sectionData('/enquiries?q=rahima')['enquiries'];
        $this->assertSame(1, $byName['total'], 'An old enquiry far past the first page is still found.');
        $this->assertSame('Rahima Begum', $byName['data'][0]['name']);

        $this->assertSame(1, $this->sectionData('/enquiries?status=closed')['enquiries']['total']);
        $this->assertSame(25, $this->sectionData('/enquiries?status=new')['enquiries']['total']);
        $this->assertSame(1, $this->sectionData('/enquiries?q='.urlencode('100%'))['enquiries']['total']);
        $this->assertSame(1, $this->sectionData('/enquiries?q=_')['enquiries']['total'], 'An underscore is a plain character.');
        $this->assertStringContainsString('q=rahima', (string) $byName['first_page_url'], 'Page links keep the search.');
    }

    public function test_application_search_and_status_cover_every_page(): void
    {
        foreach (range(1, 22) as $index) {
            $this->application('applicant'.$index.'@example.test', 'Applicant '.$index, 'pending');
        }
        $this->application('karim@example.test', 'Karim Traders', 'rejected', now()->subYear());

        $byName = $this->sectionData('/partnerships?q=KARIM')['applications'];
        $this->assertSame(1, $byName['total']);
        $this->assertSame('Karim Traders', $byName['data'][0]['name']);
        $this->assertSame(1, $this->sectionData('/partnerships?status=rejected')['applications']['total']);
        $this->assertSame(22, $this->sectionData('/partnerships?status=pending')['applications']['total']);
    }

    public function test_benefits_fund_search_and_status_cover_every_page(): void
    {
        $buyer = User::factory()->dealer()->create(['name' => 'Benefit Buyer']);
        foreach (range(1, 27) as $index) {
            $this->benefitsEntry($this->order($buyer, 'ORD-B-'.$index), 'reserved');
        }
        $other = User::factory()->dealer()->create(['name' => 'Selim Store']);
        $this->benefitsEntry($this->order($other, 'ORD-SELIM-1'), 'reversed', now()->subYear());

        $byBuyer = $this->sectionData('/benefits-fund?q=selim')['benefits_entries'];
        $this->assertSame(1, $byBuyer['total']);
        $this->assertSame('ORD-SELIM-1', $byBuyer['data'][0]['order_number']);
        $this->assertSame(1, $this->sectionData('/benefits-fund?q=ord-selim')['benefits_entries']['total']);
        $this->assertSame(1, $this->sectionData('/benefits-fund?status=reversed')['benefits_entries']['total']);
        $this->assertSame(27, $this->sectionData('/benefits-fund?status=reserved')['benefits_entries']['total']);
    }

    public function test_operations_tables_search_and_filter_each_table_on_the_server(): void
    {
        foreach (range(1, 23) as $index) {
            $this->application('field'.$index.'@example.test', 'Field Applicant '.$index, 'pending');
        }
        $this->application('nasir@example.test', 'Nasir Agency', 'approved', now()->subYear());

        $table = fn (string $query): array => collect($this->actingAs($this->owner)->onPortal('sadmin')
            ->getJson('/operations/staff-operations'.$query)->assertOk()->json('data.tables'))
            ->firstWhere('key', 'dealer_applications');

        $all = $table('');
        $this->assertSame(24, $all['page']['total']);
        $this->assertTrue($all['filter']['searchable']);
        $this->assertSame(['pending', 'approved', 'rejected'], $all['filter']['statuses']);

        $found = $table('?dealer_applications_q=nasir');
        $this->assertSame(1, $found['page']['total'], 'The record on a later page is found.');
        $this->assertSame('nasir', $found['filter']['q']);
        $this->assertSame(1, $table('?dealer_applications_status=approved')['page']['total']);
        $this->assertSame(0, $table('?dealer_applications_q=nasir&dealer_applications_status=pending')['page']['total']);
        $this->assertSame(24, $table('?dealer_applications_status=not-a-status')['page']['total']);
        $this->assertSame(0, $table('?dealer_applications_q='.urlencode('%'))['page']['total'], 'A percent sign is a plain character.');
    }

    public function test_every_operations_table_accepts_a_search_and_status(): void
    {
        $keys = ['territories', 'monthly_targets', 'territory_orders', 'my_targets', 'dealer_applications', 'salary_register', 'salary_contracts',
            'policy_schedules', 'reserve_requests', 'clawback_recoveries', 'held_allocations', 'opening_holds', 'opening_reviews'];
        $query = http_build_query(collect($keys)->flatMap(fn (string $key): array => [$key.'_q' => 'x', $key.'_status' => 'approved'])->all());
        $seen = [];
        foreach (['staff-operations', 'field-sales', 'payroll', 'policy-schedules', 'reserve-payouts', 'commission-holds', 'financial-readiness'] as $section) {
            $tables = $this->actingAs($this->owner)->onPortal('sadmin')->getJson('/operations/'.$section.'?'.$query)->assertOk()->json('data.tables');
            foreach ($tables as $table) {
                $seen[] = $table['key'];
                $this->assertSame($table['filter']['searchable'] ? 'x' : '', $table['filter']['q']);
            }
        }

        $this->assertEmpty(array_diff(array_diff($keys, ['territory_orders']), $seen), 'Every table was loaded with its search.');
    }

    /** @return array<string, mixed> */
    private function sectionData(string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json('props.data');
    }

    private function enquiry(string $name, string $phone, string $status, $createdAt = null): void
    {
        DB::table('enquiries')->insert(['name' => $name, 'phone' => $phone, 'topic' => 'Dealer enquiry', 'message' => 'Please call me.',
            'status' => $status, 'created_at' => $createdAt ?? now(), 'updated_at' => $createdAt ?? now()]);
    }

    private function application(string $email, string $name, string $status, $createdAt = null): void
    {
        DB::table('dealer_applications')->insert(['id' => (string) Str::uuid(), 'submitted_by' => $this->owner->id, 'email' => $email,
            'data' => json_encode(['name' => $name, 'phone' => '01712345678', 'role' => 'dealer'], JSON_THROW_ON_ERROR),
            'status' => $status, 'created_at' => $createdAt ?? now(), 'updated_at' => $createdAt ?? now()]);
    }

    private function order(User $buyer, string $number): Order
    {
        return Order::create([
            'number' => $number,
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '100.00',
            'paid' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    private function benefitsEntry(Order $order, string $status, $createdAt = null): void
    {
        DB::table('commission_allocations')->insert([
            'order_id' => $order->id,
            'category' => RetainedFundLedger::BenefitsCategory,
            'rate_percentage' => '0.00',
            'cash_basis' => '100.00',
            'amount' => '5.00',
            'status' => $status,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }
}
