<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\OrderService;
use App\Models\CommissionAllocation;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlaceCodeCommissionRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->seedClassicPlaceCodes();
    }

    public function test_assignment_uses_place_code_and_unassigned_district_code_retains_commission(): void
    {
        $rangpur = PlaceCode::query()->where('code', 'MNHC-102')->firstOrFail();
        $district = PlaceCode::factory()->district($rangpur)->create(['code' => 'MNHC-301', 'name' => 'Rangpur']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => 'MNHC-401', 'name' => 'Rangpur Sadar']);
        $union = PlaceCode::factory()->union($upazila)->create(['code' => 'MNHC-501', 'name' => 'Rangpur City Corporation']);
        $rangpur->update(['base_place_id' => $district->id]);
        $district->update(['base_place_id' => $upazila->id]);
        $upazila->update(['base_place_id' => $union->id]);

        $divisionDealer = User::factory()->dealer()->create(['status' => 'active']);
        $admin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($admin)->onPortal('sadmin')->post('/commands/area-dealers', [
            'user_id' => $divisionDealer->id,
            'level' => 'division',
            'place_code_id' => $rangpur->id,
            'monthly_quota' => '10000.00',
        ]);
        if ($response->exception) {
            throw $response->exception;
        }
        $response->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('area_dealer_assignments', [
            'user_id' => $divisionDealer->id,
            'place_code_id' => $rangpur->id,
            'level' => 'division',
            'division' => 'Rangpur',
        ]);

        // Buyer purchases under Rangpur division code 102; district 301 has no assignee.
        $buyer = User::factory()->dealer()->create([
            'status' => 'active',
            'division' => 'Rangpur',
            'district' => 'Rangpur',
            'profile' => ['place_code' => 'MNHC-102', 'upazila' => 'Rangpur Sadar', 'union' => 'Rangpur City Corporation'],
        ]);

        $product = Product::factory()->create(['mrp' => '20000.00', 'dealer_price' => '10000.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'allow_credit' => true,
            'reason' => 'Approve place-code area routing',
        ])->assertOk();
        $order->refresh()->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());

        $snapshot = $order->fresh()->commission_policy_snapshot;
        $this->assertSame('MNHC-102', $snapshot['geography']['codes']['division']);
        $this->assertSame('MNHC-301', $snapshot['geography']['codes']['district']);
        $this->assertSame('MNHC-401', $snapshot['geography']['codes']['upazila']);
        $this->assertSame('MNHC-501', $snapshot['geography']['codes']['union']);
        $this->assertSame('Rangpur City Corporation', $snapshot['geography']['union']);

        $divisionAlloc = CommissionAllocation::where('order_id', $order->id)->where('category', 'area_division')->first();
        $this->assertNotNull($divisionAlloc);
        $this->assertSame($divisionDealer->id, $divisionAlloc->user_id);
        $this->assertSame('MNHC-102', $divisionAlloc->metadata['place_code'] ?? null);

        $districtAlloc = CommissionAllocation::where('order_id', $order->id)->where('category', 'area_district')->first();
        $this->assertNotNull($districtAlloc);
        $this->assertNull($districtAlloc->user_id);
        $this->assertSame('unassigned_reserve', $districtAlloc->status);
        $this->assertSame('MNHC-301', $districtAlloc->metadata['place_code'] ?? null);
        $this->assertSame('company_geographic_reserve', $districtAlloc->metadata['retained_by'] ?? null);
    }

    public function test_unassigned_named_district_code_does_not_pay_district_commission(): void
    {
        $dhaka = PlaceCode::query()->where('code', 'MNHC-101')->firstOrFail();
        PlaceCode::factory()->district($dhaka)->create(['code' => 'MNHC-201', 'name' => 'Dhaka']);

        $buyer = User::factory()->dealer()->create([
            'status' => 'active',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'profile' => ['place_code' => 'MNHC-101'],
        ]);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'allow_credit' => true,
            'reason' => 'Approve unassigned district code case',
        ])->assertOk();
        $order->refresh()->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());

        // Without base places, only the division code is known from the buyer profile.
        $districtAlloc = CommissionAllocation::where('order_id', $order->id)->where('category', 'area_district')->first();
        $this->assertNotNull($districtAlloc);
        $this->assertNull($districtAlloc->user_id);
        $this->assertSame('unassigned_reserve', $districtAlloc->status);
    }
}
