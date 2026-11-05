<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TableTotalsAndExportsTest extends TestCase
{
    use RefreshDatabase;

    private function dealer(): User
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);

        return $dealer;
    }

    private function order(User $dealer, string $price): Order
    {
        $product = Product::factory()->create(['mrp' => '5000.00', 'dealer_price' => $price, 'active' => true, 'stock' => 100]);

        return app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function claim(User $dealer, Order $order, string $amount, string $channel): void
    {
        app(OrderService::class)->claim($dealer, $order->fresh(), [
            'channel' => $channel,
            'reference' => 'TXN'.Str::upper(Str::random(10)),
            'amount' => $amount,
        ]);
    }

    /** @return array<string, mixed> */
    private function sectionData(User $staff, string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($staff)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json('props.data');
    }

    public function test_payment_totals_cover_the_whole_filtered_list_by_status_and_method(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = $this->dealer();
        $this->claim($dealer, $this->order($dealer, '1000.00'), '1000.00', 'bKash');
        $this->claim($dealer, $this->order($dealer, '500.00'), '500.00', 'Nagad');
        $verified = DB::table('payment_claims')->where('channel', 'bKash')->value('id');
        DB::table('payment_claims')->where('id', $verified)->update(['status' => 'verified']);

        $data = $this->sectionData($owner, '/payments');

        $this->assertSame(['count' => 1, 'amount' => '1000.00'], $data['claim_totals']['by_status']['verified']);
        $this->assertSame(['count' => 1, 'amount' => '500.00'], $data['claim_totals']['by_status']['pending']);
        $this->assertSame(['bKash' => '1000.00'], $data['claim_totals']['verified_by_channel']);
        $this->assertSame(['pending' => 1, 'verified' => 1], collect($data['claim_status_counts'])->sortKeys()->all());
    }

    public function test_order_totals_count_every_matching_order_and_ignore_rejected_money(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = $this->dealer();
        $this->order($dealer, '1000.00');
        $this->order($dealer, '700.00')->update(['status' => 'rejected']);

        $totals = $this->sectionData($owner, '/orders')['order_totals'];

        $this->assertSame(2, $totals['orders']);
        $this->assertSame('1000.00', $totals['value']);
        $this->assertSame('1000.00', $totals['to_pay']);
    }

    public function test_ledger_and_audit_lists_search_on_the_server(): void
    {
        $owner = User::factory()->staff()->create();
        Audit::record($owner->id, 'order.approved', 'ORD-1');
        Audit::record($owner->id, 'account.updated', 'USER-2');

        $events = $this->sectionData($owner, '/audit?q=order.approved')['events'];

        $this->assertSame(1, $events['total']);
        $this->assertSame('order.approved', $events['data'][0]['action']);
    }

    public function test_collection_sheet_and_tax_register_download_for_finance_only(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $dealer = $this->dealer();
        $this->claim($dealer, $this->order($dealer, '1000.00'), '1000.00', 'bKash');
        $range = '&format=xlsx&from='.now('Asia/Dhaka')->subDay()->toDateString().'&to='.now('Asia/Dhaka')->toDateString();

        foreach (['collections', 'tds-register'] as $type) {
            $this->actingAs($finance)->onPortal('manage')->get('/commands/operations/reports?type='.$type.$range)
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        $this->actingAs($dealer)->onPortal('dealer')->get('/commands/operations/reports?type=collections'.$range)->assertForbidden();
    }
}
