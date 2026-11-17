<?php

namespace Tests\Feature;

use App\Domain\Orders\InvoiceBuyerPlace;
use App\Domain\Orders\OrderInvoice;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use App\Support\LetterheadContacts;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The partner block and the contact lines at the top of an invoice.
 */
class InvoiceBuyerPlaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{division: PlaceCode, district: PlaceCode, upazila: PlaceCode, union: PlaceCode} */
    private function rangpurPlaces(): array
    {
        $division = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $district = PlaceCode::factory()->district($division)->create(['name' => 'Rangpur']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['name' => 'Mithapukur']);
        $union = PlaceCode::factory()->union($upazila)->create(['name' => 'Badarganj']);

        return compact('division', 'district', 'upazila', 'union');
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function levels(): array
    {
        return [
            'division' => ['division', 'Division Dealer', 'Rangpur'],
            'district' => ['district', 'District Dealer', 'Rangpur'],
            'upazila' => ['upazila', 'Upazila Dealer', 'Mithapukur, Rangpur, Rangpur'],
            'union' => ['union', 'Union Dealer', 'Badarganj, Mithapukur, Rangpur, Rangpur'],
        ];
    }

    #[DataProvider('levels')]
    public function test_a_dealer_is_printed_with_their_dealer_type_and_an_address_that_climbs_to_the_division(string $level, string $type, string $address): void
    {
        $dealer = User::factory()->dealer()->inPlace($this->rangpurPlaces()[$level])->create();

        $this->assertSame(['level' => $level, 'address' => $address], app(InvoiceBuyerPlace::class)->for($dealer));

        $payload = app(OrderInvoice::class)->payload($this->orderFor($dealer), 'none');
        $this->assertSame($type, $payload['buyer']['designation']);
        $this->assertSame($address, $payload['buyer']['area']);
    }

    public function test_the_dealer_type_is_written_in_bangla_when_the_page_is_in_bangla(): void
    {
        App::setLocale('bn');
        $dealer = User::factory()->dealer()->inPlace($this->rangpurPlaces()['union'])->create();

        $payload = app(OrderInvoice::class)->payload($this->orderFor($dealer), 'none');

        $this->assertSame('ইউনিয়ন ডিলার', $payload['buyer']['designation']);
    }

    public function test_a_sub_dealer_keeps_the_sub_dealer_title_with_their_union_address(): void
    {
        $sub = User::factory()->subDealer()->inPlace($this->rangpurPlaces()['union'])->create();

        $payload = app(OrderInvoice::class)->payload($this->orderFor($sub), 'none');

        $this->assertSame('Sub-dealer', $payload['buyer']['designation']);
        $this->assertSame('Badarganj, Mithapukur, Rangpur, Rangpur', $payload['buyer']['area']);
    }

    public function test_a_partner_with_no_place_code_is_typed_from_the_deepest_place_name_they_saved(): void
    {
        $union = User::factory()->dealer()->create(['division' => 'Rangpur', 'district' => 'Rangpur', 'profile' => ['upazila' => 'Mithapukur', 'union' => 'Badarganj']]);
        $upazila = User::factory()->dealer()->create(['division' => 'Rangpur', 'district' => 'Rangpur', 'profile' => ['upazila' => 'Mithapukur']]);
        $district = User::factory()->dealer()->create(['division' => 'Rangpur', 'district' => 'Dinajpur', 'profile' => []]);
        $division = User::factory()->dealer()->create(['division' => 'Rajshahi', 'district' => null, 'profile' => []]);

        $this->assertSame(['level' => 'union', 'address' => 'Badarganj, Mithapukur, Rangpur, Rangpur'], app(InvoiceBuyerPlace::class)->for($union));
        $this->assertSame(['level' => 'upazila', 'address' => 'Mithapukur, Rangpur, Rangpur'], app(InvoiceBuyerPlace::class)->for($upazila));
        $this->assertSame(['level' => 'district', 'address' => 'Dinajpur'], app(InvoiceBuyerPlace::class)->for($district));
        $this->assertSame(['level' => 'division', 'address' => 'Rajshahi'], app(InvoiceBuyerPlace::class)->for($division));

        $payload = app(OrderInvoice::class)->payload($this->orderFor($union), 'none');
        $this->assertSame('Union Dealer', $payload['buyer']['designation']);
    }

    public function test_a_partner_with_no_place_and_no_saved_names_gets_the_plain_dealer_title(): void
    {
        $dealer = User::factory()->dealer()->create(['division' => null, 'district' => null, 'profile' => []]);

        $payload = app(OrderInvoice::class)->payload($this->orderFor($dealer), 'none');

        $this->assertSame(['level' => null, 'address' => null], app(InvoiceBuyerPlace::class)->for($dealer));
        $this->assertSame('Dealer', $payload['buyer']['designation']);
        $this->assertSame('—', $payload['buyer']['area']);
    }

    public function test_the_invoice_page_shows_the_type_above_the_details_and_every_contact_line(): void
    {
        config(['madina.app_domain' => 'madina.example.com', 'app.url' => 'https://madina.example.com']);
        $dealer = User::factory()->dealer()->inPlace($this->rangpurPlaces()['upazila'])->create();
        $order = $this->orderFor($dealer);

        $html = (string) app(OrderInvoice::class)->printHtml($dealer, $order)->getContent();

        $this->assertStringContainsString('>Upazila Dealer</td>', $html);
        $this->assertStringContainsString('Mithapukur, Rangpur, Rangpur', $html);
        $this->assertStringContainsString('WhatsApp: +8801730532966', $html);
        $this->assertStringContainsString('Mobile: +8801730532965', $html);
        $this->assertStringContainsString('madinanaturalhealthcare@gmail.com', $html);
        $this->assertStringContainsString('href="https://madina.example.com/"', $html);
        foreach (['whatsapp', 'call', 'mail', 'web'] as $icon) {
            $this->assertStringContainsString('branding/contact-icons/'.$icon.'.svg', $html);
        }
        $this->assertStringNotContainsString(public_path(), $html, 'the page must use web addresses, not file paths');
    }

    public function test_the_contact_lines_follow_the_owners_settings_and_leave_out_a_missing_website(): void
    {
        config(['madina.app_domain' => 'localhost']);
        SiteCustomization::save(['contact_email' => 'owner@example.org']);

        $lines = LetterheadContacts::lines();

        $this->assertSame('owner@example.org', $lines['email']);
        $this->assertSame('', $lines['website']);
        $this->assertSame('', $lines['website_url']);

        config(['madina.app_domain' => 'madina.example.com', 'app.url' => 'https://madina.example.com']);
        $this->assertSame('madina.example.com', LetterheadContacts::lines()['website']);
    }

    public function test_every_contact_icon_file_exists(): void
    {
        foreach (LetterheadContacts::icons() as $path) {
            $this->assertFileExists($path);
        }
    }

    private function orderFor(User $partner): Order
    {
        $order = Order::create([
            'user_id' => $partner->id,
            'number' => 'INV-PLC-'.Str::random(6),
            'status' => 'approved',
            'total' => '810.00',
            'paid' => '810.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::uuid()),
        ]);
        $product = Product::factory()->create(['mrp' => '450.00', 'dealer_price' => '270.00']);
        $order->lines()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 3,
            'mrp' => $product->mrp,
            'unit_price' => '270.00',
            'total' => '810.00',
        ]);

        return $order;
    }
}
