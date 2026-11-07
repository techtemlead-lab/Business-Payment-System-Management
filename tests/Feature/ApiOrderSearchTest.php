<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiOrderSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_search_ignores_letter_case_and_treats_percent_and_underscore_as_plain_text(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $product = Product::factory()->create();
        $numbers = ['MNH-ABC-1001', 'MNH-A_C-1002', 'MNH-100%-1003'];
        foreach ($numbers as $number) {
            $order = app(OrderService::class)->create($dealer, [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'idempotency_key' => (string) Str::uuid(),
            ]);
            DB::table('orders')->where('id', $order->id)->update(['number' => $number]);
        }
        $this->actingAs($dealer);

        $this->assertSame(['MNH-ABC-1001'], $this->search('abc'));
        $this->assertSame(['MNH-A_C-1002'], $this->search('a_c'), 'An underscore must not match any single letter.');
        $this->assertSame(['MNH-100%-1003'], $this->search('100%'), 'A percent sign must not match everything.');
        $this->assertSame(['MNH-100%-1003'], $this->search('%'));
        $this->assertSame(['MNH-A_C-1002'], $this->search('_'));
        $this->assertSame([], $this->search('\\'));
    }

    /**
     * @return list<string>
     */
    private function search(string $term): array
    {
        $numbers = $this->getJson('/api/v1/orders?'.http_build_query(['q' => $term]))->assertOk()->json('data.*.number');
        sort($numbers);

        return $numbers;
    }
}
