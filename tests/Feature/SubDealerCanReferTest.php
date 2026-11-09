<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\ImportWorkbook;
use App\Domain\Finance\OpeningBalanceImport;
use App\Domain\Staff\StaffOperations;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Sub-dealers get a referral link, so their code has to work wherever a dealer's does.
 */
class SubDealerCanReferTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $subDealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->seedClassicPlaceCodes();
        $this->owner = User::factory()->staff()->create();
        $this->subDealer = User::factory()->subDealer()->create([
            'status' => 'active',
            'dealer_code' => 'MNHC-100001-000009',
            'refer_code' => 'MNHC-100001-000009',
        ]);
        ReferralTreePath::attachUser($this->subDealer->id, null);
    }

    public function test_a_sub_dealers_code_finds_them_as_the_referrer(): void
    {
        $partners = app(PartnerAccountProvisioning::class);

        $this->assertSame($this->subDealer->id, $partners->findSponsor('MNHC-100001-000009')?->id);
        $this->assertSame($this->subDealer->id, $partners->findSponsor('100001-000009')?->id);
    }

    public function test_a_suspended_sub_dealer_cannot_refer(): void
    {
        $this->subDealer->forceFill(['status' => 'suspended'])->save();

        $this->assertNull(app(PartnerAccountProvisioning::class)->findSponsor('MNHC-100001-000009'));
    }

    public function test_an_application_with_a_sub_dealers_code_is_approved_under_them(): void
    {
        app(StaffOperations::class)->review($this->owner, $this->application(), 'approved', 'Checked the application', 'MNHC-102');

        $applicant = User::query()->where('email', 'applicant@example.test')->firstOrFail();
        $this->assertSame($this->subDealer->id, $applicant->sponsor_id);
        $this->assertDatabaseHas('referral_tree_paths', ['ancestor_id' => $this->subDealer->id, 'descendant_id' => $applicant->id, 'depth' => 1]);
    }

    public function test_the_add_partner_form_takes_a_sub_dealers_code(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1985123456701',
            'date_of_birth' => '1985-05-01',
            'name' => 'New Dealer',
            'name_bn' => 'নতুন ডিলার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-102',
            'reference_code' => 'MNHC-100001-000009',
            'phone' => '01710000031',
            'email' => 'new-dealer@example.test',
            'reason' => 'Referred by a sub-dealer',
        ])->assertRedirect('/accounts')->assertSessionHas('success');

        $this->assertSame($this->subDealer->id, User::query()->where('email', 'new-dealer@example.test')->firstOrFail()->sponsor_id);
    }

    public function test_the_office_can_name_a_sub_dealer_as_the_referrer(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);

        $this->actingAs($this->owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'name' => $dealer->name,
            'sponsor_id' => $this->subDealer->id,
            'reason' => 'Corrected the referrer',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame($this->subDealer->id, $dealer->fresh()->sponsor_id);
        $this->assertDatabaseHas('referral_tree_paths', ['ancestor_id' => $this->subDealer->id, 'descendant_id' => $dealer->id, 'depth' => 1]);
    }

    public function test_the_excel_import_takes_a_sub_dealers_code_as_the_referrer(): void
    {
        Storage::fake('local');
        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview($this->owner, 'partners', $this->workbook([
            ['D-101', 'Imported dealer', 'imported@example.test', '01700000001', 'dealer', 'MNHC-100001-000009', 'MNHC-102'],
        ]), 'Approved partner register');
        $this->assertDatabaseHas('data_import_batches', ['id' => $id, 'status' => 'preview']);

        $imports->apply($this->owner, $id, 'Checked against the register');

        $partner = User::where('dealer_code', 'D-101')->firstOrFail();
        $this->assertSame($this->subDealer->id, $partner->sponsor_id);
        $this->assertDatabaseHas('referral_tree_paths', ['ancestor_id' => $this->subDealer->id, 'descendant_id' => $partner->id, 'depth' => 1]);
    }

    public function test_a_sub_dealer_can_be_chosen_on_the_staff_form_and_sees_who_they_referred(): void
    {
        $referred = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $this->subDealer->id]);
        ReferralTreePath::attachUser($referred->id, $this->subDealer->id);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/accounts/create?intent=staff')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sponsors', fn ($sponsors) => collect($sponsors)->contains('id', $this->subDealer->id)));

        $this->actingAs($this->subDealer)->onPortal('subdealer')->get('/network')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.members', fn ($members) => collect($members)->contains('id', $referred->id)));
    }

    public function test_a_sub_dealer_referrer_is_paid_by_the_same_rule_as_a_dealer(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $second = User::factory()->subDealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($second->id, null);
        $this->subDealer->forceFill(['sponsor_id' => $second->id])->save();
        ReferralTreePath::reparentUser($this->subDealer->id, $second->id);
        $buyer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $this->subDealer->id]);
        ReferralTreePath::attachUser($buyer->id, $this->subDealer->id);

        $order = Order::create([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'sub-dealer-refer'),
            'number' => 'ORD-SUB-REFER-1',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);
        $product = Product::factory()->create(['mrp' => Money::decimal(Money::cents('20000.00'))]);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'mrp' => $product->mrp, 'unit_price' => '10000.00', 'total' => '10000.00']);

        app(CommissionService::class)->processOrder($order);

        $direct = CommissionAllocation::where('order_id', $order->id)->where('category', 'direct_referral')->firstOrFail();
        $this->assertSame($this->subDealer->id, $direct->user_id);
        $this->assertEquals(1000.00, (float) $direct->amount);
        $this->assertSame('available', $direct->status);
        $generationTwo = CommissionAllocation::where('order_id', $order->id)->where('category', 'd2d_gen2')->firstOrFail();
        $this->assertSame($second->id, $generationTwo->user_id);
        $this->assertEquals(200.00, (float) $generationTwo->amount);
    }

    private function application(): string
    {
        $id = (string) Str::uuid();
        DB::table('dealer_applications')->insert([
            'id' => $id,
            'submitted_by' => null,
            'email' => 'applicant@example.test',
            'data' => json_encode([
                'name' => 'Applicant Dealer',
                'email' => 'applicant@example.test',
                'phone' => '01700000011',
                'role' => 'dealer',
                'division' => 'Dhaka',
                'district' => 'Dhaka',
                'sponsor_code' => 'MNHC-100001-000009',
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'source' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param list<list<string>> $rows */
    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'madina-import-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Data');
        $writer->addRow(Row::fromValues(ImportWorkbook::Columns['partners']));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        try {
            return UploadedFile::fake()->createWithContent('source.xlsx', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}
