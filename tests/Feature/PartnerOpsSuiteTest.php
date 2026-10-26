<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerCreateMedia;
use App\Domain\Orders\OrderInvoice;
use App\Domain\Staff\PartnerStaffLink;
use App\Domain\Support\TourGuide;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerOpsSuiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeOrder(User $user, string $number, string $total = '1000.00'): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'number' => $number,
            'status' => 'approved',
            'total' => $total,
            'paid' => $total,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $number),
        ]);
        $product = Product::factory()->create(['mrp' => $total, 'dealer_price' => $total]);
        $order->lines()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'mrp' => $product->mrp,
            'unit_price' => $total,
            'total' => $total,
        ]);

        return $order;
    }

    public function test_partner_earnings_ledger_includes_source_and_rate_columns(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'name' => 'Source Buyer', 'dealer_code' => 'D-SRC']);
        $order = $this->makeOrder($buyer, 'INV-LEDGER-1');

        DB::table('commission_allocations')->insert([
            'order_id' => $order->id,
            'user_id' => $dealer->id,
            'category' => 'direct_referral',
            'rate_percentage' => '5.00',
            'cash_basis' => '1000.00',
            'amount' => '50.00',
            'original_amount' => '50.00',
            'status' => 'available',
            'metadata' => json_encode(['generation' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('wallets')->insert([
            'user_id' => $dealer->id,
            'available' => '50.00',
            'held' => '0.00',
            'commission_held' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($dealer)
            ->onPortal('dealer')
            ->get('/earnings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('section', 'earnings')
                ->has('data.earnings.0', fn ($row) => $row
                    ->where('category', 'direct_referral')
                    ->where('rate_percentage', '5.00')
                    ->where('order_number', 'INV-LEDGER-1')
                    ->where('source_code', 'D-SRC')
                    ->etc())
                ->has('data.withdrawal_balances'));
    }

    public function test_order_invoice_watermarks_partner_copy_and_keeps_admin_clean(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $order = $this->makeOrder($dealer, 'INV-WM-1', '500.00');

        $invoice = app(OrderInvoice::class);
        $this->assertSame('dealer', $invoice->watermarkFor($dealer, $order->fresh('user')));
        $this->assertSame('none', $invoice->watermarkFor($admin, $order->fresh('user')));

        $this->actingAs($dealer)
            ->onPortal('dealer')
            ->get('/orders/'.$order->id.'/invoice')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_order_invoice_payload_matches_partner_letter_template(): void
    {
        $sponsor = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MNHC107',
            'refer_code' => 'REFSP001',
        ]);
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'sponsor_id' => $sponsor->id,
            'dealer_code' => 'MNHC114',
            'phone' => '01793748891',
            'district' => 'Dhaka',
            'profile' => ['shop_name' => 'Madani Avenue Shop', 'upazila' => 'Vatara'],
        ]);

        $order = Order::create([
            'user_id' => $dealer->id,
            'number' => 'INV-33',
            'status' => 'approved',
            'total' => '4800.00',
            'paid' => '4800.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'INV-33'),
        ]);
        $product = Product::factory()->create([
            'name' => 'Nutralife',
            'unit' => '500 ml',
            'mrp' => '800.00',
            'dealer_price' => '400.00',
        ]);
        $order->lines()->create([
            'product_id' => $product->id,
            'name' => 'Nutralife',
            'sku' => $product->sku,
            'quantity' => 12,
            'mrp' => '800.00',
            'unit_price' => '400.00',
            'total' => '4800.00',
        ]);

        $payload = app(OrderInvoice::class)->payload($order->fresh(['user', 'lines']), 'dealer');

        $this->assertSame('Holistic Nutracare', $payload['company']['manufacturer']);
        $this->assertSame('Madina Natural Health Care', $payload['company']['marketed_by']);
        $this->assertSame('MNHC107', $payload['buyer']['referred_code']);
        $this->assertSame('Dealer', $payload['buyer']['designation']);
        $this->assertSame('500 ml', $payload['lines'][0]['pack_size']);
        $this->assertSame('9600.00', $payload['lines'][0]['mrp_bill']);
        $this->assertSame('4800.00', $payload['lines'][0]['commission']);
        $this->assertSame('4800.00', $payload['lines'][0]['total']);
        $this->assertSame('50%', $payload['lines'][0]['remark']);
        $this->assertSame(12, $payload['totals']['quantity']);
        $this->assertStringContainsString('Taka', $payload['totals']['in_words']);
        $this->assertSame('DEALER COPY', $payload['watermark_label']);

        $html = view('invoices.order-letter', $payload)->render();
        $this->assertStringContainsString('Invoice', $html);
        $this->assertStringContainsString('Marketed By:', $html);
        $this->assertStringContainsString('Referred Dealer Code:', $html);
        $this->assertStringContainsString('MRP Rate Bill', $html);
        $this->assertStringContainsString('Customer Signature', $html);
        $this->assertStringContainsString('Authority Signature', $html);
    }

    public function test_support_ticket_flow_and_tour_completion(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($dealer)
            ->onPortal('dealer')
            ->post('/commands/support-tickets', [
                'category' => 'withdrawal',
                'subject' => 'Need payout help',
                'body' => 'My withdrawal is still pending review.',
            ])
            ->assertRedirect();

        $ticketId = DB::table('support_tickets')->where('user_id', $dealer->id)->value('id');
        $this->assertNotNull($ticketId);

        $this->actingAs($admin)
            ->onPortal('sadmin')
            ->post('/commands/support-tickets/'.$ticketId.'/reply', [
                'body' => 'We are reviewing your payout.',
                'status' => 'in_progress',
            ])
            ->assertRedirect();

        $this->assertSame('in_progress', DB::table('support_tickets')->where('id', $ticketId)->value('status'));

        app(TourGuide::class)->complete($dealer, 'overview');
        $this->assertDatabaseHas('tour_completions', [
            'user_id' => $dealer->id,
            'page_key' => 'overview',
        ]);

        $this->actingAs($dealer)
            ->onPortal('dealer')
            ->post('/commands/tours/restart', ['page' => 'overview'])
            ->assertRedirect();
        $this->assertDatabaseMissing('tour_completions', [
            'user_id' => $dealer->id,
            'page_key' => 'overview',
        ]);
    }

    public function test_optional_create_media_and_staff_workspace_switch(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        app(PartnerStaffLink::class)->link($admin, $dealer, 'employee', ['orders', 'accounts']);
        $dealer->refresh();
        $this->assertTrue($dealer->hasLinkedStaffAccess());
        $this->assertFalse($dealer->usingStaffWorkspace());

        $this->actingAs($dealer)
            ->onPortal('dealer')
            ->post('/commands/workspace-mode', ['mode' => 'staff'])
            ->assertRedirect('/');

        $this->assertTrue($dealer->fresh()->usingStaffWorkspace());
        $this->assertContains('orders', $dealer->fresh()->capabilities());

        $this->actingAs($dealer)
            ->onPortal('dealer')
            ->post('/commands/workspace-mode', ['mode' => 'partner'])
            ->assertRedirect('/');
        $this->assertFalse($dealer->fresh()->usingStaffWorkspace());

        $photo = UploadedFile::fake()->image('dealer.jpg', 200, 200);
        app(PartnerCreateMedia::class)->attachOptional($admin, $dealer, ['photo' => $photo]);
        $this->assertNotEmpty(DB::table('kyc_documents')->where('user_id', $dealer->id)->where('kind', 'photo')->value('id'));
        $this->assertNotNull($dealer->fresh()->profile['photo_url'] ?? null);
    }
}
