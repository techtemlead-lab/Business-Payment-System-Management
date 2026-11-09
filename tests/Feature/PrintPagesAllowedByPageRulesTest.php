<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderInvoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The print pages run a small script for their Print button. The page security rules (Content
 * Security Policy) only run a script that carries this page's nonce and never run an inline click
 * handler, so a page that misses either one shows a Print button that does nothing.
 */
class PrintPagesAllowedByPageRulesTest extends TestCase
{
    use RefreshDatabase;

    private const InlineHandler = '/\son[a-z]+\s*=\s*["\']/i';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_packing_slip_print_button_is_allowed_to_run(): void
    {
        $staff = User::factory()->staff()->create(['status' => 'active']);
        $order = $this->order(User::factory()->dealer()->create(['status' => 'active']));

        $response = $this->actingAs($staff)->onPortal('sadmin')->post('/commands/bulk/order-slips', ['ids' => [(string) $order->id]]);

        $this->assertEveryScriptCarriesThePageNonce($response);
        $response->assertSee('data-print', false);
    }

    public function test_the_invoice_print_page_is_allowed_to_print_for_a_partner_and_for_staff(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $order = $this->order($dealer);

        $this->assertEveryScriptCarriesThePageNonce($this->actingAs($dealer)->onPortal('dealer')->get('/orders/'.$order->id.'/invoice/print'));
        $this->assertEveryScriptCarriesThePageNonce($this->actingAs(User::factory()->staff()->create(['status' => 'active']))->onPortal('sadmin')->get('/orders/'.$order->id.'/invoice/print'));
    }

    public function test_the_invoice_print_button_works_without_an_inline_click_handler(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $response = $this->actingAs($dealer)->onPortal('dealer')->get('/orders/'.$this->order($dealer)->id.'/invoice/print')->assertOk();

        $response->assertSee('<button type="button" class="btn-primary" data-print>', false);
        $response->assertSee("document.querySelectorAll('[data-print]')", false);
        $response->assertSee('window.addEventListener(\'load\'', false);
        $response->assertDontSee('onclick', false);
    }

    public function test_the_invoice_pdf_is_built_without_any_script(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $html = view('invoices.order-letter', app(OrderInvoice::class)->payload($this->order($dealer), 'dealer'))->render();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('data-print', $html);
    }

    public function test_the_payment_receipt_print_buttons_are_allowed_to_run(): void
    {
        Storage::fake('local');
        $this->enableFinancialPosting();
        $partner = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'DLR-7001']);
        $receiptId = $this->paidReceiptId($partner, User::factory()->staff('finance')->create());

        $response = $this->actingAs($partner)->onPortal('dealer')->get('/payment-receipts/'.$receiptId.'/print');

        $this->assertEveryScriptCarriesThePageNonce($response);
    }

    public function test_no_page_template_relies_on_an_inline_handler_or_a_script_without_a_nonce(): void
    {
        foreach (File::allFiles(resource_path('views')) as $template) {
            $source = $template->getContents();
            $this->assertDoesNotMatchRegularExpression(self::InlineHandler, $source, $template->getRelativePathname().' has an inline handler the browser will block.');

            preg_match_all('/<script\b.*$/mi', $source, $scripts);
            foreach ($scripts[0] as $script) {
                if (str_contains($script, 'application/json') || str_contains($script, ' src=')) {
                    continue;
                }
                $this->assertStringContainsString('nonce=', $script, $template->getRelativePathname().' has a script the browser will block.');
            }
        }
    }

    private function assertEveryScriptCarriesThePageNonce(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $policy));
        $html = (string) $response->getContent();

        preg_match_all('/<script\b[^>]*>/i', $html, $scripts);
        $this->assertNotEmpty($scripts[0], 'The page has no print script.');
        foreach ($scripts[0] as $script) {
            $this->assertStringContainsString('nonce="'.$policy[1].'"', $script);
        }
        $this->assertDoesNotMatchRegularExpression(self::InlineHandler, $html);
    }

    private function order(User $partner): Order
    {
        $product = Product::factory()->create();
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $partner->id,
            'status' => 'approved',
            'total' => '150.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 3, 'mrp' => '50.00', 'unit_price' => '50.00', 'total' => '150.00']);

        return $order;
    }

    private function paidReceiptId(User $partner, User $payer): int
    {
        $id = (string) Str::uuid();
        DB::table('wallets')->updateOrInsert(['user_id' => $partner->id], ['available' => '0.00', 'held' => '500.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $partner->id, 'amount' => '500.00', 'withheld_amount' => '50.00', 'net_amount' => '450.00',
            'status' => 'approved', 'channel' => 'Bank', 'destination' => encrypt('0123456789012'), 'idempotency_key' => (string) Str::uuid(),
            'reviewed_by' => User::factory()->staff('finance')->create()->id, 'created_at' => now()->subDay(), 'updated_at' => now()]);
        $this->actingAs($payer)->post('/commands/withdrawals/'.$id.'/record-payment', [
            'method' => 'nagad', 'wallet_number' => '01811000000', 'payment_reference' => 'NG-0001',
            'paid_at' => now()->subHour()->format('Y-m-d H:i:s'), 'note' => 'Sent by Nagad', 'receipt' => UploadedFile::fake()->image('nagad.png'),
        ])->assertSessionHasNoErrors();

        return (int) DB::table('payout_receipts')->where('withdrawal_id', $id)->value('id');
    }
}
