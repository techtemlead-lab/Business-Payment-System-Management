<?php

namespace Tests\Feature;

use App\Domain\Finance\PolicyDraftService;
use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(string $role = 'dealer'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function product(): Product
    {
        return Product::create(['sku' => 'TEST-'.Str::random(8), 'name' => 'Test Product', 'category' => 'Test', 'mrp' => '200.00', 'dealer_price' => '140.00', 'stock' => 20]);
    }

    private function order(User $u, Product $p, array $extra = [])
    {
        return app(OrderService::class)->create($u, [...['items' => [['product_id' => $p->id, 'quantity' => 5]], 'idempotency_key' => (string) Str::uuid()], ...$extra]);
    }

    public function test_public_pages_and_staff_only_registration(): void
    {
        foreach (['/', '/products', '/dealers', '/about', '/contact', '/login'] as $url) {
            $this->get($url)->assertOk();
        }$this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_dealer_cannot_read_other_account_order_or_company_lists(): void
    {
        $a = $this->user();
        $b = $this->user();
        $o = $this->order($b, $this->product());
        $this->actingAs($a)->get('/workspace/orders/'.$o->id)->assertNotFound();
        $this->get('/workspace/accounts')->assertNotFound();
        $this->get('/workspace')->assertInertia(fn (Assert $p) => $p->where('data.stats.order_count', 0));
    }

    public function test_editor_overview_does_not_receive_company_orders(): void
    {
        $o = $this->order($this->user(), $this->product());
        $this->actingAs($this->user('editor'))->get('/company')->assertInertia(fn (Assert $p) => $p->where('data.stats.order_count', 0)->where('data.orders.total', 0));
        $this->get('/company/orders/'.$o->id)->assertNotFound();
        $this->get('/company/payments')->assertForbidden();
    }

    public function test_dealer_price_and_subdealer_discount_are_server_calculated(): void
    {
        $p = $this->product();
        $u = $this->user('sub_dealer');
        $this->actingAs($u)->post('/commands/orders', ['items' => [['product_id' => $p->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(), 'total' => '1.00'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['user_id' => $u->id, 'total' => '240.00']);
        $this->assertDatabaseHas('order_lines', ['unit_price' => '120.00']);
    }

    public function test_duplicate_order_key_is_idempotent_but_changed_payload_is_rejected(): void
    {
        $u = $this->user();
        $p = $this->product();
        $key = (string) Str::uuid();
        $data = ['items' => [['product_id' => $p->id, 'quantity' => 2]], 'idempotency_key' => $key];
        $this->actingAs($u)->post('/commands/orders', $data)->assertRedirect();
        $this->post('/commands/orders', $data)->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $data['items'][0]['quantity'] = 3;
        $this->post('/commands/orders', $data)->assertStatus(409);
    }

    public function test_payment_claim_needs_staff_verification_and_cannot_be_posted_twice(): void
    {
        $u = $this->user();
        $admin = $this->user('super_admin');
        $o = $this->order($u, $this->product(), ['reference' => 'PROOF-1', 'channel' => 'Bank', 'amount' => '500.00']);
        $claim = DB::table('payment_claims')->value('id');
        $this->assertSame('0.00', $o->paid);
        $this->actingAs($u)->post('/commands/claims/'.$claim.'/verify', ['approve' => 1, 'reason' => 'Confirmed'])->assertForbidden();
        $this->actingAs($admin)->post('/commands/claims/'.$claim.'/verify', ['approve' => 1, 'reason' => 'Confirmed in account'])->assertRedirect();
        $this->assertSame('500.00', $o->fresh()->paid);
        $this->post('/commands/claims/'.$claim.'/verify', ['approve' => 1, 'reason' => 'Duplicate'])->assertSessionHasErrors();
        $this->assertDatabaseCount('receipts', 1);
    }

    public function test_partial_dispatch_changes_stock_and_refuses_oversupply(): void
    {
        $u = $this->user();
        $admin = $this->user('super_admin');
        $p = $this->product();
        $o = $this->order($u, $p);
        $s = app(OrderService::class);
        $s->decision($admin, $o, 'approved', 'Confirmed credit sale', true);
        $line = $o->lines()->first();
        $data = ['courier' => 'Test', 'tracking' => 'TRACK-1', 'idempotency_key' => (string) Str::uuid(), 'items' => [['line_id' => $line->id, 'quantity' => 2]]];
        $this->actingAs($admin)->post('/commands/orders/'.$o->id.'/dispatch', $data)->assertRedirect();
        $this->assertEquals(18, $p->fresh()->stock);
        $this->assertSame('partially_dispatched', $o->fresh()->status);
        $data['items'][0]['quantity'] = 4;
        $data['idempotency_key'] = (string) Str::uuid();
        $this->post('/commands/orders/'.$o->id.'/dispatch', $data)->assertSessionHasErrors();
        $this->assertEquals(18, $p->fresh()->stock);
        $this->assertDatabaseCount('shipments', 1);
    }

    public function test_suspended_accounts_are_logged_out_and_profiles_are_not_self_editable(): void
    {
        $u = $this->user();
        $this->actingAs($u)->patch('/commands/accounts/'.$u->id, ['name' => 'Changed'])->assertForbidden();
        $u->update(['status' => 'suspended']);
        $this->get('/workspace')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_withdrawals_remain_gated_and_policy_drafts_can_be_published_for_review(): void
    {
        $this->actingAs($this->user())->post('/commands/withdrawals', ['amount' => '200.00'])->assertSessionHasErrors('policy');
        $this->assertDatabaseCount('withdrawals', 0);

        $preparer = $this->user('finance');
        $reviewer = $this->user('super_admin');
        $id = app(PolicyDraftService::class)->create($preparer, [
            'name' => 'Review draft',
            'account_type' => 'dealer',
            'components' => [['name' => 'referral', 'rate' => '10']],
            'notes' => 'Document purchase-value rates before go-live.',
        ]);
        $this->actingAs($preparer)->onPortal('manage')->post("/commands/policies/{$id}/publish")
            ->assertRedirect()
            ->assertSessionHasErrors('policy');
        $this->actingAs($reviewer)->onPortal('sadmin')->post("/commands/policies/{$id}/publish")
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertDatabaseHas('policy_drafts', ['id' => $id, 'status' => 'published', 'published_by' => $reviewer->id]);
    }

    public function test_money_and_flat_salary_use_exact_minor_units(): void
    {
        $this->assertSame(20001, Money::cents('200.01'));
        $this->assertSame('200.01', Money::decimal(20001));
        foreach ([0, 999999, 1000000, 2499999, 2500000, 4000000, 5000000] as $purchases) {
            $this->assertSame(3, Money::salaryTier($purchases));
        }
    }

    public function test_public_directory_excludes_private_profiles(): void
    {
        $u = $this->user();
        $u->update(['nid' => '1234567890', 'phone' => 'Private phone', 'profile' => ['father_name' => 'Private parent']]);
        $this->get('/dealers')->assertDontSee('Private phone')->assertDontSee('Private parent')->assertDontSee('1234567890');
        $this->actingAs($u)->get('/workspace')->assertInertia(fn (Assert $p) => $p->missing('auth.user.nid')->missing('auth.user.profile'));
    }

    public function test_customer_supplied_payment_amount_cannot_overpay_order(): void
    {
        $p = $this->product();
        $this->actingAs($this->user())->post('/commands/orders', ['items' => [['product_id' => $p->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'reference' => 'TEST', 'channel' => 'Bank', 'amount' => '900.00'])->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('orders', 0);
    }
}
