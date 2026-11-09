<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The owner's rule: the 2% and 1% referral follow the generation only, never the person's
 * position, so a sub-dealer at that generation is paid like a dealer.
 */
class ReferralByGenerationOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sub_dealer_at_the_second_and_third_generation_is_paid(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $thirdGeneration = $this->partner('sub_dealer', null);
        $secondGeneration = $this->partner('sub_dealer', $thirdGeneration);
        $firstGeneration = $this->partner('dealer', $secondGeneration);
        $buyer = $this->partner('dealer', $firstGeneration);

        app(CommissionPosting::class)->process($this->deliveredOrder($buyer), null);

        $this->assertPaid('d2d_gen2', $secondGeneration, '200.00');
        $this->assertPaid('d2d_gen3', $thirdGeneration, '100.00');
    }

    public function test_the_same_generation_pays_the_same_whatever_the_position(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $dealerAtTop = $this->partner('dealer', null);
        $subDealerAtTop = $this->partner('sub_dealer', null);
        $orders = [];
        foreach ([$dealerAtTop, $subDealerAtTop] as $top) {
            $first = $this->partner('dealer', $top);
            $orders[$top->id] = $this->deliveredOrder($this->partner('dealer', $first));
            app(CommissionPosting::class)->process($orders[$top->id], null);
        }

        $this->assertSame(
            (string) DB::table('commission_allocations')->where(['order_id' => $orders[$dealerAtTop->id]->id, 'category' => 'd2d_gen2'])->value('amount'),
            (string) DB::table('commission_allocations')->where(['order_id' => $orders[$subDealerAtTop->id]->id, 'category' => 'd2d_gen2'])->value('amount'),
        );
    }

    private function assertPaid(string $category, User $partner, string $amount): void
    {
        $row = DB::table('commission_allocations')->where(['category' => $category, 'user_id' => $partner->id])->sole();

        $this->assertSame($amount, (string) $row->amount);
        $this->assertContains($row->status, ['available', 'maturing']);
    }

    private function partner(string $role, ?User $sponsor): User
    {
        $factory = $role === 'dealer' ? User::factory()->dealer() : User::factory();
        $partner = $factory->create(['role' => $role, 'status' => 'active', 'sponsor_id' => $sponsor?->id]);
        ReferralTreePath::attachUser($partner->id, $sponsor?->id);

        return $partner;
    }

    private function deliveredOrder(User $buyer): Order
    {
        $order = Order::query()->create([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', (string) Str::uuid()),
            'number' => 'ORD-REF-'.Str::upper(Str::random(6)),
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);
        $product = Product::factory()->create(['mrp' => '20000.00', 'dealer_price' => '10000.00']);
        $order->lines()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'mrp' => $product->mrp,
            'unit_price' => '10000.00',
            'total' => '10000.00',
        ]);

        return $order->fresh();
    }
}
