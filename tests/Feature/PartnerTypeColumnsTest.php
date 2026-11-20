<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAppointment;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The office tables name the kind of partner and the place in a column of their own, and the code has its own column. */
class PartnerTypeColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{division: PlaceCode, district: PlaceCode, upazila: PlaceCode, union: PlaceCode, pourashava: PlaceCode} */
    private function places(): array
    {
        $division = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $district = PlaceCode::factory()->district($division)->create(['name' => 'Rangpur']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['name' => 'Pirganj']);
        $union = PlaceCode::factory()->union($upazila)->create(['name' => 'Kabilpur']);
        $pourashava = PlaceCode::factory()->union($upazila)->create(['name' => 'Syedpur Pourashava']);

        return compact('division', 'district', 'upazila', 'union', 'pourashava');
    }

    private function partnerAt(PlaceCode $place, string $role = 'dealer'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'dealer_code' => 'MNHC'.random_int(100000, 999999), 'profile' => ['place_code_id' => $place->id, 'place_code' => $place->code]]);
    }

    public function test_the_table_text_is_the_kind_then_plain_names_without_level_words(): void
    {
        $places = $this->places();
        $sub = $this->partnerAt($places['union'], 'sub_dealer');
        $people = [
            'division' => $this->partnerAt($places['division']),
            'district' => $this->partnerAt($places['district']),
            'upazila' => $this->partnerAt($places['upazila']),
            'union' => $this->partnerAt($places['union']),
            'pourashava' => $this->partnerAt($places['pourashava']),
        ];

        $english = app(PartnerAppointment::class)->tableCells([...$people, 'sub' => $sub], 'en');
        $this->assertSame(['title' => 'Divisional dealer', 'place' => 'Rangpur'], $english[$people['division']->id]);
        $this->assertSame(['title' => 'District dealer', 'place' => 'Rangpur'], $english[$people['district']->id]);
        $this->assertSame(['title' => 'Thana dealer', 'place' => 'Pirganj, Rangpur'], $english[$people['upazila']->id]);
        $this->assertSame(['title' => 'Union dealer', 'place' => 'Kabilpur, Pirganj'], $english[$people['union']->id]);
        $this->assertSame(['title' => 'Pourashava dealer', 'place' => 'Syedpur, Pirganj'], $english[$people['pourashava']->id]);
        $this->assertSame(['title' => 'Sub-dealer', 'place' => 'Kabilpur, Pirganj'], $english[$sub->id]);

        $bangla = app(PartnerAppointment::class)->tableCells([...$people, 'sub' => $sub], 'bn');
        $this->assertSame('বিভাগীয় ডিলার', $bangla[$people['division']->id]['title']);
        $this->assertSame('সাব-ডিলার', $bangla[$sub->id]['title']);
        $this->assertSame('পীরগঞ্জ, রংপুর', $bangla[$people['upazila']->id]['place']);
        $this->assertStringNotContainsString('উপজেলা', $bangla[$people['union']->id]['place']);
        $this->assertStringNotContainsString('ইউনিয়ন', $bangla[$people['union']->id]['place']);
        $this->assertStringContainsString('পীরগঞ্জ', $bangla[$people['union']->id]['place']);
    }

    public function test_a_partner_with_no_appointed_place_has_no_place_line(): void
    {
        $plain = User::factory()->dealer()->create();
        $sub = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        $cells = app(PartnerAppointment::class)->tableCells([$plain, $sub], 'en');

        $this->assertSame(['title' => null, 'place' => null], $cells[$plain->id]);
        $this->assertSame(['title' => 'Sub-dealer', 'place' => null], $cells[$sub->id]);
        $this->assertSame('Dealer', PartnerAppointment::cellText($cells[$plain->id], 'Dealer'));
        $this->assertSame('Union dealer — Kabilpur, Pirganj', PartnerAppointment::cellText(['title' => 'Union dealer', 'place' => 'Kabilpur, Pirganj']));
    }

    public function test_the_office_orders_page_carries_the_type_and_keeps_the_profile_private(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $places = $this->places();
        $union = $this->partnerAt($places['union']);
        $order = $this->order($union, 'submitted', '60.00');
        $order->lines()->create($this->lineFields(1, 0));

        $this->actingAs($owner)->onPortal('sadmin')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.orders.data.0.user.dealer_code', $union->dealer_code)
                ->where('data.orders.data.0.user.partner_type.title', 'Union dealer')
                ->where('data.orders.data.0.user.partner_type.place', fn (string $place): bool => str_contains($place, 'Pirganj'))
                ->missing('data.orders.data.0.user.profile')
                // A fresh unpaid ৳60 order: the whole price, nothing received, all of it still to pay, and a nought sent.
                ->where('data.orders.data.0.total', '60.00')
                ->where('data.orders.data.0.paid', '0.00')
                ->where('data.orders.data.0.lines.0.dispatched', 0)
                ->where('data.orders_table.totals.sums.price', '60.00')
                ->where('data.orders_table.totals.sums.received', '0.00')
                ->where('data.orders_table.totals.sums.to_pay', '60.00')
                ->where('data.orders_table.totals.sums.delivered_value', '0.00'));
    }

    public function test_the_orders_download_has_the_name_then_code_then_partner_type(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $places = $this->places();
        $upazila = $this->partnerAt($places['upazila']);
        $this->order($upazila, 'submitted', '60.00');

        $csv = $this->actingAs($owner)->onPortal('sadmin')->get('/exports/orders?section=orders')->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));

        $headers = $lines[0];
        $typeAt = null;
        foreach ($headers as $index => $header) {
            if (in_array($header, [__('portal.csv_col_partner_type', [], 'en'), __('portal.csv_col_partner_type', [], 'bn')], true)) {
                $typeAt = $index;
            }
        }
        $this->assertNotNull($typeAt);
        $this->assertSame($upazila->name, $lines[1][$typeAt - 2]);
        $this->assertSame($upazila->dealer_code, $lines[1][$typeAt - 1]);
        $this->assertMatchesRegularExpression('/ — (Pirganj, Rangpur|পীরগঞ্জ, রংপুর)$/u', $lines[1][$typeAt]);
    }

    private function order(User $user, string $status, string $total): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $user->id,
            'status' => $status,
            'total' => $total,
            'paid' => '0.00',
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    /** @return array<string, mixed> */
    private function lineFields(int $quantity, int $dispatched): array
    {
        $product = Product::factory()->create();

        return ['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'quantity' => $quantity, 'dispatched' => $dispatched, 'mrp' => '60.00', 'unit_price' => '60.00', 'total' => '60.00'];
    }
}
