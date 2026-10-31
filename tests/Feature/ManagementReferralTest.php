<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Geography\ManagementReferral;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManagementReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedClassicPlaceCodes();
    }

    public function test_blank_reference_refers_dealer_to_management_code(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', $this->dealerPayload([
            'email' => 'blank-refer@example.com',
            'phone' => '01712000001',
            'place_code' => 'MNHC-102',
        ]))->assertRedirect('/accounts');

        $user = User::query()->where('email', 'blank-refer@example.com')->firstOrFail();
        $this->assertNull($user->sponsor_id);
        $this->assertSame('management', $user->profile['referred_by'] ?? null);
        $this->assertSame('MNHC-100', $user->profile['management_refer_code'] ?? null);
    }

    public function test_management_code_refers_sub_dealer(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $union = $this->unionCode();

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', $this->dealerPayload([
            'role' => 'sub_dealer',
            'email' => 'mgmt-sub@example.com',
            'phone' => '01712000002',
            'place_code' => $union,
            'reference_code' => '100',
            'shop_name' => 'City Shop',
        ]))->assertRedirect('/sub-dealers');

        $user = User::query()->where('email', 'mgmt-sub@example.com')->firstOrFail();
        $this->assertSame('sub_dealer', $user->role);
        $this->assertNull($user->sponsor_id);
        $this->assertSame('management', $user->profile['referred_by'] ?? null);
        $this->assertSame('MNHC-100', $user->profile['management_refer_code'] ?? null);
    }

    public function test_unknown_reference_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->from('/accounts/create')->post('/commands/accounts', $this->dealerPayload([
            'email' => 'missing-refer@example.com',
            'phone' => '01712000003',
            'place_code' => 'MNHC-103',
            'reference_code' => 'NO-SUCH-CODE',
        ]))->assertRedirect('/accounts/create')->assertSessionHasErrors('reference_code');

        $this->assertDatabaseMissing('users', ['email' => 'missing-refer@example.com']);
    }

    public function test_personal_refer_code_cannot_copy_the_management_code(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->from('/accounts/create')->post('/commands/accounts', $this->dealerPayload([
            'email' => 'copied-mgmt@example.com',
            'phone' => '01712000004',
            'place_code' => 'MNHC-104',
            'refer_code' => 'MNHC-100',
        ]))->assertRedirect('/accounts/create')->assertSessionHasErrors('refer_code');
    }

    public function test_owner_saves_management_referral_and_staff_is_forbidden(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $editor = User::factory()->staff('editor')->create();
        $management = PlaceCode::query()->where('code', 'MNHC-100')->firstOrFail();

        $this->post('/commands/place-codes/management-referral', [
            'enabled' => 1,
            'auto_blank_reference' => 1,
            'route_referral_slices' => 1,
            'version' => 0,
        ])->assertRedirect();

        $this->actingAs($editor)->onPortal('manage')->post('/commands/place-codes/management-referral', [
            'enabled' => 0,
            'auto_blank_reference' => 0,
            'route_referral_slices' => 0,
            'place_code_id' => $management->id,
            'version' => 0,
        ])->assertForbidden();

        $this->actingAs($owner)->onPortal('sadmin')->from('/place-codes?level=management')->post('/commands/place-codes/management-referral', [
            'enabled' => 1,
            'auto_blank_reference' => 0,
            'route_referral_slices' => 1,
            'place_code_id' => $management->id,
            'version' => 0,
        ])->assertRedirect('/place-codes?level=management')->assertSessionHas('success');

        $saved = app(ManagementReferral::class)->settings();
        $this->assertFalse($saved['auto_blank_reference']);
        $this->assertSame(1, $saved['version']);

        $this->actingAs($owner)->postJson('/api/v1/place-codes/management-referral', [
            'enabled' => true,
            'auto_blank_reference' => true,
            'route_referral_slices' => false,
            'place_code_id' => $management->id,
            'version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.route_referral_slices', false)
            ->assertJsonPath('data.place_code', 'MNHC-100');
    }

    public function test_stale_or_non_management_place_is_rejected(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $management = PlaceCode::query()->where('code', 'MNHC-100')->firstOrFail();
        $division = PlaceCode::query()->where('code', 'MNHC-101')->firstOrFail();
        $payload = [
            'enabled' => 1,
            'auto_blank_reference' => 1,
            'route_referral_slices' => 1,
            'place_code_id' => $management->id,
            'version' => 0,
        ];

        $this->actingAs($owner)->onPortal('sadmin')->from('/place-codes?level=management')
            ->post('/commands/place-codes/management-referral', $payload)
            ->assertRedirect();

        $this->actingAs($owner)->onPortal('sadmin')->from('/place-codes?level=management')
            ->post('/commands/place-codes/management-referral', $payload)
            ->assertRedirect('/place-codes?level=management')
            ->assertSessionHasErrors('version');

        $this->actingAs($owner)->onPortal('sadmin')->from('/place-codes?level=management')
            ->post('/commands/place-codes/management-referral', [
                ...$payload,
                'place_code_id' => $division->id,
                'version' => 1,
            ])->assertRedirect('/place-codes?level=management')
            ->assertSessionHasErrors('place_code_id');
    }

    public function test_place_codes_and_account_create_expose_management_referral(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/place-codes?level=management')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.management_referral.place_code', 'MNHC-100')
                ->where('data.management_referral.enabled', true)
                ->where('data.management_referral.can_manage', true)
                ->where('data.management_referral.fund', ManagementReferral::FundRetainedBy));

        $this->actingAs($owner)->onPortal('sadmin')->get('/accounts/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('management_referral.place_code', 'MNHC-100')
                ->where('management_referral.auto_blank_reference', true));
    }

    public function test_refer_preview_uses_management_for_blank_and_management_code(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->getJson('/commands/identity/refer-preview')
            ->assertOk()
            ->assertJsonPath('data.warnings', [])
            ->assertJsonPath('data.consumers.direct_referral.fund', 'management')
            ->assertJsonPath('data.consumers.direct_referral.refer_code', 'MNHC-100')
            ->assertJsonPath('data.consumers.d2d_gen2.fund', 'management')
            ->assertJsonPath('data.consumers.d2d_gen3.fund', 'management');

        $this->actingAs($admin)->onPortal('sadmin')->getJson('/commands/identity/refer-preview?reference_code=MNHC-100')
            ->assertOk()
            ->assertJsonPath('data.warnings', [])
            ->assertJsonPath('data.consumers.management_fund', true);

        $this->actingAs($admin)->onPortal('sadmin')->getJson('/commands/identity/refer-preview?reference_code=NO-SUCH')
            ->assertOk()
            ->assertJsonPath('data.consumers.direct_referral', null);
    }

    public function test_buyer_without_sponsor_posts_referral_slices_into_management_fund(): void
    {
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', $this->dealerPayload([
            'email' => 'fund-buyer@example.com',
            'phone' => '01712000011',
            'place_code' => 'MNHC-105',
        ]))->assertRedirect('/accounts');
        $buyer = User::query()->where('email', 'fund-buyer@example.com')->firstOrFail();
        $order = $this->deliveredOrder($buyer);

        app(CommissionService::class)->processOrder($order);

        foreach (['direct_referral' => 1000.0, 'd2d_gen2' => 200.0, 'd2d_gen3' => 100.0] as $category => $amount) {
            $row = CommissionAllocation::query()->where('order_id', $order->id)->where('category', $category)->first();
            $this->assertNotNull($row);
            $this->assertNull($row->user_id);
            $this->assertSame('pending', $row->status);
            $this->assertSame(ManagementReferral::FundRetainedBy, $row->metadata['retained_by'] ?? null);
            $this->assertEquals($amount, (float) $row->amount);
        }
        $area = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'area_management')->first();
        $this->assertNotNull($area);
        $this->assertSame(ManagementReferral::FundRetainedBy, $area->metadata['retained_by'] ?? null);
        $this->assertFundMatchesPostedRows();

        $detail = app(RetainedFundLedger::class)->detail();
        $managementCategories = array_column($detail['management_area'], 'category');
        $this->assertContains('direct_referral', $managementCategories);
        $this->assertContains('area_management', $managementCategories);
        $this->assertNotContains('direct_referral', array_column($detail['unassigned_geographic'], 'category'));

        $expectedFund = (float) app(ManagementReferral::class)->fundAmount();
        $this->actingAs($admin)->onPortal('sadmin')->get('/treasury')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.flow.management_reserve_2', fn (mixed $value): bool => (float) $value === $expectedFund));
    }

    public function test_personal_sponsor_keeps_ten_percent_and_sends_d2d_to_management_fund(): void
    {
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
        $sponsor = User::factory()->dealer()->create(['status' => 'active', 'refer_code' => 'PERSONAL-REF']);
        $buyer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $sponsor->id]);
        $order = $this->deliveredOrder($buyer);

        app(CommissionService::class)->processOrder($order);

        $direct = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'direct_referral')->first();
        $this->assertNotNull($direct);
        $this->assertSame($sponsor->id, $direct->user_id);
        $this->assertEquals(1000.0, (float) $direct->amount);

        foreach (['d2d_gen2' => 200.0, 'd2d_gen3' => 100.0] as $category => $amount) {
            $row = CommissionAllocation::query()->where('order_id', $order->id)->where('category', $category)->first();
            $this->assertNotNull($row);
            $this->assertNull($row->user_id);
            $this->assertSame('pending', $row->status);
            $this->assertSame(ManagementReferral::FundRetainedBy, $row->metadata['retained_by'] ?? null);
            $this->assertEquals($amount, (float) $row->amount);
        }
    }

    public function test_disabled_referral_routing_keeps_unassigned_reserve(): void
    {
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
        $owner = User::factory()->create(['role' => 'super_admin']);
        $management = PlaceCode::query()->where('code', 'MNHC-100')->firstOrFail();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/place-codes/management-referral', [
            'enabled' => 1,
            'auto_blank_reference' => 1,
            'route_referral_slices' => 0,
            'place_code_id' => $management->id,
            'version' => 0,
        ])->assertRedirect();

        $buyer = User::factory()->dealer()->create(['status' => 'active']);
        $order = $this->deliveredOrder($buyer);
        app(CommissionService::class)->processOrder($order);

        $this->assertDatabaseHas('commission_allocations', [
            'order_id' => $order->id,
            'category' => 'direct_referral_breakage',
            'status' => 'unassigned_reserve',
        ]);
        $this->assertDatabaseMissing('commission_allocations', [
            'order_id' => $order->id,
            'category' => 'direct_referral',
        ]);
        $area = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'area_management')->first();
        $this->assertSame(ManagementReferral::FundRetainedBy, $area?->metadata['retained_by'] ?? null);
    }

    public function test_assigning_a_sponsor_clears_management_referral_and_clearing_it_restores_the_fund(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', $this->dealerPayload([
            'email' => 'switch-refer@example.com',
            'phone' => '01712000021',
            'place_code' => 'MNHC-106',
        ]))->assertRedirect('/accounts');
        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', $this->dealerPayload([
            'email' => 'personal-sponsor@example.com',
            'phone' => '01712000022',
            'place_code' => 'MNHC-107',
            'reference_code' => 'MNHC-106',
        ]))->assertRedirect('/accounts');

        $managed = User::query()->where('email', 'switch-refer@example.com')->firstOrFail();
        $sponsor = User::query()->where('email', 'personal-sponsor@example.com')->firstOrFail();
        $this->assertSame($managed->id, $sponsor->sponsor_id);

        $this->actingAs($admin)->onPortal('sadmin')->from('/accounts')->patch('/commands/accounts/'.$sponsor->id, [
            'sponsor_id' => '',
            'reason' => 'Return this partner to the management code',
        ])->assertRedirect();

        $sponsor->refresh();
        $this->assertNull($sponsor->sponsor_id);
        $this->assertSame('management', $sponsor->profile['referred_by'] ?? null);

        $this->actingAs($admin)->onPortal('sadmin')->from('/accounts')->patch('/commands/accounts/'.$sponsor->id, [
            'sponsor_id' => $managed->id,
            'reason' => 'Assign a personal sponsor',
        ])->assertRedirect();

        $sponsor->refresh();
        $this->assertSame($managed->id, $sponsor->sponsor_id);
        $this->assertArrayNotHasKey('referred_by', $sponsor->profile ?? []);
    }

    public function test_legacy_account_without_sponsor_is_stamped_when_management_code_exists(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'dealer_code' => 'LEG-440',
            'name' => 'Legacy Blank',
            'email' => 'legacy-blank@example.com',
            'phone' => '01712000031',
            'reason' => 'Created without a personal referrer',
        ])->assertRedirect('/accounts');

        $user = User::query()->where('email', 'legacy-blank@example.com')->firstOrFail();
        $this->assertNull($user->sponsor_id);
        $this->assertSame('management', $user->profile['referred_by'] ?? null);
        $this->assertSame('MNHC-100', $user->profile['management_refer_code'] ?? null);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function dealerPayload(array $overrides): array
    {
        return [
            'role' => 'dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '19911234567890111',
            'date_of_birth' => '1991-02-02',
            'name' => 'Managed Partner',
            'name_bn' => 'ম্যানেজড পার্টনার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'reason' => 'Management referral onboarding',
            ...$overrides,
        ];
    }

    private function unionCode(): string
    {
        $division = PlaceCode::query()->where('code', 'MNHC-102')->firstOrFail();
        $district = PlaceCode::factory()->district($division)->create(['code' => 'MNHC-351', 'name' => 'Fund District']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => 'MNHC-451', 'name' => 'Fund Upazila']);
        $union = PlaceCode::factory()->union($upazila)->create(['code' => 'MNHC-551', 'name' => 'Fund Union']);

        return (string) $union->code;
    }

    private function deliveredOrder(User $buyer): Order
    {
        $order = Order::query()->create([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', (string) Str::uuid()),
            'number' => 'ORD-MGMT-'.Str::upper(Str::random(6)),
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

        return $order;
    }

    private function assertFundMatchesPostedRows(): void
    {
        $posted = CommissionAllocation::query()
            ->where('status', '!=', 'reversed')
            ->get()
            ->filter(fn (CommissionAllocation $row): bool => ($row->metadata['retained_by'] ?? null) === ManagementReferral::FundRetainedBy
                || $row->category === 'area_management');
        $sum = number_format($posted->sum(fn (CommissionAllocation $row): float => (float) $row->amount), 2, '.', '');

        $this->assertSame($sum, app(ManagementReferral::class)->fundAmount());
    }
}
