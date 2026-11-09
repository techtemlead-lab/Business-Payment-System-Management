<?php

namespace Tests\Feature;

use App\Domain\Finance\PayrollService;
use App\Domain\Inventory\BranchOperations;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\PartnerAgreementVersion;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** What the owner's browser check found on the product, place, salary and report pages. */
class QaOwnerModulesRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_product_editor_keeps_its_remove_form_outside_the_save_form(): void
    {
        $editor = (string) file_get_contents(resource_path('js/pages/ProductEditor.tsx'));

        $this->assertGreaterThan(strpos($editor, '</form>'), strpos($editor, '<ProductRemovePanel'), 'A form inside a form never sends.');
    }

    public function test_a_place_with_neither_code_nor_name_is_refused(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/place-codes', [
            'level' => PlaceCode::LevelManagement,
            'code' => '',
            'name' => '',
            'parent_id' => null,
        ])->assertSessionHasErrors(['code' => __('portal.err_pc_code_or_name_required')]);

        $this->assertSame(0, PlaceCode::query()->count());
    }

    public function test_a_wrong_place_code_reaches_the_server_and_is_refused(): void
    {
        $hub = (string) file_get_contents(resource_path('js/components/PlaceCodesHub.tsx'));
        $this->assertStringNotContainsString(".replace(/\\D/g, '')", $hub, 'Letters must not vanish while typing, or the person never learns the code was wrong.');

        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/place-codes', [
            'level' => PlaceCode::LevelManagement,
            'code' => 'BADCODE',
            'name' => '',
            'parent_id' => null,
        ])->assertSessionHasErrors(['code' => __('portal.err_pc_code_format')]);

        $this->assertSame(0, PlaceCode::query()->count());
    }

    public function test_the_scorecard_counts_verified_money_only_on_delivered_goods(): void
    {
        Carbon::setTestNow('2026-11-10 06:00:00');
        $finance = User::factory()->staff('finance')->create();
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim']);
        $this->claim($this->order($dealer, 'delivered', '400.00'), '400.00');
        // More than the goods were worth: only the goods' value counts.
        $this->claim($this->order($dealer, 'delivered', '100.00'), '150.00');
        foreach (['partially_dispatched' => '1400.00', 'rejected' => '280.00', 'approved' => '140.00', 'cancelled' => '60.00'] as $status => $amount) {
            $this->claim($this->order($dealer, $status, $amount), $amount);
        }
        $partly = $this->order($dealer, 'partly_delivered', '300.00');
        $this->claim($partly, '300.00');
        $this->deliverPart($partly, 2, '50.00');

        $partner = $this->actingAs($finance)->onPortal('manage')->get('/partner-scorecard')->assertOk()
            ->original->getData()['page']['props']['data']['partner_scorecard']['partners']['rows']['data'][0];

        $this->assertSame('600.00', $partner['payments_verified_amount'], '400 + 100 capped + 100 of the partly delivered order.');
        $this->assertSame(7, $partner['payments_verified'], 'The count of checked payments stays a count of payments.');
    }

    public function test_salary_for_a_month_with_no_contract_says_nothing_was_made(): void
    {
        Carbon::setTestNow('2026-12-10 06:00:00');
        $owner = User::factory()->create(['role' => 'super_admin']);

        try {
            app(PayrollService::class)->prepare($owner, '2026-10');
            $this->fail('Nothing was prepared, so the page must not say saved.');
        } catch (ValidationException $refused) {
            $this->assertSame(__('portal.err_payroll_nothing_to_prepare'), $refused->errors()['period_month'][0]);
        }
        $this->assertSame(0, DB::table('payroll_entries')->count());
    }

    public function test_the_staff_table_filter_wraps_on_a_phone(): void
    {
        $css = (string) file_get_contents(resource_path('css/dashboard.css'));

        $this->assertStringContainsString('.app-shell form.filter-bar.order-filters{flex-wrap:wrap}', $css);
    }

    public function test_a_new_draft_is_a_draft_and_only_a_removed_product_is_archived(): void
    {
        $owner = User::factory()->staff()->create();
        $draft = Product::factory()->create(['name' => 'Never Published', 'active' => false]);
        $removed = Product::factory()->create(['name' => 'Taken Off Sale', 'stock' => 7]);

        $this->actingAs($owner)->delete('/commands/products/'.$removed->id, ['reason' => 'No longer part of the range'])
            ->assertRedirect('/catalog?product_state=archived');
        $this->assertNotNull($removed->refresh()->archived_at);
        $this->assertNull($draft->refresh()->archived_at);

        $archivedTab = $this->catalog($owner, '/catalog?product_state=archived');
        $this->assertSame(['Taken Off Sale'], array_column($archivedTab['products'], 'name'));
        $this->assertTrue($archivedTab['products'][0]['archived']);
        $this->assertSame(1, $archivedTab['archived_count']);
        $this->assertSame(1, $archivedTab['draft_count']);

        $draftTab = $this->catalog($owner, '/catalog?product_state=draft');
        $this->assertSame(['Never Published'], array_column($draftTab['products'], 'name'));
        $this->assertFalse($draftTab['products'][0]['archived']);

        $this->actingAs($owner)->post('/commands/products/'.$removed->id.'/restore')->assertRedirect('/catalog');
        $removed->refresh();
        $this->assertTrue($removed->active);
        $this->assertNull($removed->archived_at, 'A product back on sale is no longer archived.');
    }

    public function test_the_product_screens_tell_a_draft_from_an_archived_product(): void
    {
        $drawer = (string) file_get_contents(resource_path('js/components/ProductDrawer.tsx'));
        $dialog = (string) file_get_contents(resource_path('js/components/ProductRemoveDialog.tsx'));

        $this->assertStringContainsString("t('status_draft')", $drawer);
        $this->assertStringNotContainsString('const isArchived = product.active === false', $drawer);
        $this->assertStringNotContainsString('const isArchived = product.active === false', $dialog);
    }

    public function test_an_older_product_shows_its_saved_category_and_unit_and_can_be_saved(): void
    {
        $editor = (string) file_get_contents(resource_path('js/pages/ProductEditor.tsx'));

        $this->assertStringContainsString('required={!keepsOldCategory}', $editor);
        $this->assertStringContainsString('required={!keepsOldUnit}', $editor);
        $this->assertStringContainsString('keepsOldCategory?String(form.data.category)', $editor);
        $this->assertStringContainsString('keepsOldUnit?String(form.data.unit)', $editor);
    }

    public function test_a_short_removal_reason_on_categories_and_units_says_why(): void
    {
        $hub = (string) file_get_contents(resource_path('js/components/CatalogTaxonomyHub.tsx'));

        $this->assertSame(3, substr_count($hub, 'askRemovalReason('));
        $this->assertStringContainsString("window.alert(translate('portal', 'cms_remove_reason_too_short'))", $hub);
    }

    public function test_leftover_english_and_odd_words_are_gone(): void
    {
        $bangla = (array) trans('portal', [], 'bn');
        foreach (['cms_fld_slug', 'cms_subcategories_hint', 'err_catalog_slug_required', 'imp_col_uploaded'] as $key) {
            $this->assertStringNotContainsString('(', (string) $bangla[$key], $key);
        }
        $this->assertSame('লগইন', $bangla['event_login']);
        $this->assertSame('এসআরের এলাকা সেভ হয়েছে।', $bangla['flash_sr_area_saved']);
        $this->assertStringNotContainsString('উইথড্রর', (string) trans('accounting.limit_title', [], 'bn'));
        $this->assertStringNotContainsString('সাব-ক্যাটাগরি', (string) file_get_contents(lang_path('bn/portal.php')));
        $this->assertStringNotContainsString('কর্মচারী', (string) trans('treasury.dl_payroll', [], 'bn'));

        $events = (string) file_get_contents(resource_path('js/components/EventsCalendarHub.tsx'));
        $this->assertStringContainsString("'সকাল'", $events, 'Event times read in Bangla, not AM.');
        $imports = (string) file_get_contents(resource_path('js/pages/DataImports.tsx'));
        $this->assertStringContainsString('businessDateTime(row.created_at)', $imports, 'Upload times are Bangladesh time.');
        $inventory = (string) file_get_contents(resource_path('js/components/office/sections/Inventory.tsx'));
        $this->assertStringContainsString("modal==='stock_in'?'branch_receive_into':'branch_fulfill_from'", $inventory);
    }

    public function test_the_sr_form_lists_only_people_who_can_be_chosen(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'name' => 'Company Administrator']);
        $salesRep = User::factory()->staff('sr')->create();

        $staff = collect($this->actingAs($owner)->onPortal('sadmin')->get('/operations/staff-operations')->assertOk()
            ->original->getData()['page']['props']['staff']);

        $this->assertNotNull($staff->firstWhere('id', $salesRep->id), 'names: '.$staff->pluck('name')->join(', '));
        $this->assertFalse((bool) ($staff->firstWhere('id', $owner->id)['field_sales'] ?? false), 'The server refuses giving yourself an SR area.');
        $this->assertTrue((bool) $staff->firstWhere('id', $salesRep->id)['field_sales']);
    }

    public function test_publishing_the_same_agreement_text_is_refused(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $latest = PartnerAgreementVersion::query()->orderByDesc('version')->firstOrFail();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/agreement/publish', [
            'body_bn' => $latest->body_bn,
            'body_en' => ' '.$latest->body_en.' ',
        ])->assertSessionHasErrors(['body_bn' => __('portal.err_agr_unchanged')]);

        $this->assertSame($latest->version, PartnerAgreementVersion::query()->max('version'));
    }

    public function test_the_top_sellers_table_can_be_viewed_by_a_chosen_period(): void
    {
        Carbon::setTestNow('2026-11-10 06:00:00');
        $owner = User::factory()->staff('super_admin')->create();
        $today = User::factory()->dealer()->create(['name' => 'Bought Today', 'status' => 'active']);
        $earlier = User::factory()->dealer()->create(['name' => 'Bought Earlier', 'status' => 'active']);
        $this->order($today, 'delivered', '100.00');
        $older = $this->order($earlier, 'delivered', '900.00');
        DB::table('orders')->where('id', $older->id)->update(['created_at' => '2026-11-02 06:00:00']);

        $names = fn (string $address): array => array_column($this->actingAs($owner)->onPortal('sadmin')->get($address)->assertOk()
            ->original->getData()['page']['props']['data']['top_sellers']['national'], 'name');

        $this->assertSame(['Bought Earlier', 'Bought Today'], $names('/top-sellers'));
        $this->assertSame(['Bought Today'], $names('/top-sellers?period=today'));
        $this->assertSame(['Bought Earlier'], $names('/top-sellers?period=day&from=2026-11-02'));
    }

    public function test_a_new_partner_on_another_branch_is_named_when_saved(): void
    {
        $headOffice = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $other = CompanyBranch::factory()->create(['name' => 'Rangpur Branch']);
        $owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $headOffice->id]);
        $elsewhere = User::factory()->dealer()->create(['active_branch_id' => $other->id]);
        $here = User::factory()->dealer()->create(['active_branch_id' => $headOffice->id]);
        $branches = app(BranchOperations::class);

        $this->assertSame($other->id, $branches->otherBranchOfNewPartner($owner, Request::create('/accounts'), $elsewhere)?->id);
        $this->assertNull($branches->otherBranchOfNewPartner($owner, Request::create('/accounts'), $here));
    }

    /** @return array<string, mixed> */
    private function catalog(User $viewer, string $address): array
    {
        return $this->actingAs($viewer)->onPortal('sadmin')->get($address)->assertOk()->original->getData()['page']['props']['data'];
    }

    private function order(User $buyer, string $status, string $total): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => $total,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    private function claim(Order $order, string $amount): void
    {
        DB::table('payment_claims')->insert([
            'order_id' => $order->id,
            'channel' => 'bKash',
            'reference' => 'REF'.Str::upper(Str::random(8)),
            'amount' => $amount,
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deliverPart(Order $order, int $quantity, string $unitPrice): void
    {
        $product = Product::factory()->create();
        $lineId = DB::table('order_lines')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 6,
            'mrp' => '60.00',
            'unit_price' => $unitPrice,
            'total' => '300.00',
            'is_free' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shipments')->insert([
            'order_id' => $order->id,
            'courier' => 'Test',
            'tracking' => 'T-'.Str::random(6),
            'status' => 'delivered',
            'courier_status' => 'delivered',
            'lines' => json_encode([['line_id' => $lineId, 'name' => 'Item', 'quantity' => $quantity, 'lots' => []]], JSON_THROW_ON_ERROR),
            'actor_id' => User::factory()->staff()->create()->id,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', (string) Str::uuid()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
