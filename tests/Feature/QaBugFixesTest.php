<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Finance\WithdrawalSecurityWait;
use App\Domain\Geography\DirectoryAreaFilter;
use App\Domain\Payouts\ManualPayoutService;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests for the QA-round bugs B1–B8 (2026-11-19).
 */
class QaBugFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['madina.full_payment_when_ordering' => true]);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_b1_office_cash_is_verified_from_the_order_form_with_the_counted_amount(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 50]);
        $this->actingAs($dealer)->post('/commands/orders', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(), 'channel' => 'Office'])->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->latest()->firstOrFail();
        $claim = DB::table('payment_claims')->where('order_id', $order->id)->sole();
        $verify = fn (array $data) => $this->actingAs($finance)->onPortal('manage')->post('/commands/claims/'.$claim->id.'/verify', ['approve' => '1', 'reason' => 'Cash at the counter', ...$data]);

        $verify([])->assertSessionHasErrors(['cash_counted' => __('payouts.err_cash_count_needed')]);
        $this->assertSame('pending', DB::table('payment_claims')->where('id', $claim->id)->value('status'));
        $verify(['cash_counted' => '200.00'])->assertSessionHasNoErrors();
        $this->assertSame('verified', DB::table('payment_claims')->where('id', $claim->id)->value('status'));
    }

    public function test_b2_the_wait_message_has_no_again_and_uses_bangla_digits(): void
    {
        app()->setLocale('bn');
        $text = app(WithdrawalSecurityWait::class)->reason(CarbonImmutable::parse('2026-11-19 14:40:00', 'UTC'));

        $this->assertStringNotContainsString('আবার', $text);
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', $text);
        $this->assertStringContainsString('২০২৬', $text);
    }

    public function test_b4_the_partner_notice_never_carries_the_staff_reason(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], ['available' => '90000.00', 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        $service = app(ManualPayoutService::class);
        $id = $service->request($dealer, ['amount' => '5000.00', 'channel' => 'Bank', 'destination' => '0123456789', 'idempotency_key' => (string) Str::uuid()]);
        $service->review(User::factory()->staff('finance')->create(), $id, 'approved', 'Checked account and wallet, OK');

        $body = (string) DB::table('account_notices')->where('user_id', $dealer->id)->where('body', 'like', '%অনুমোদিত%')->orWhere('body', 'like', '%approved%')->latest('id')->value('body');
        $this->assertNotSame('', $body);
        $this->assertStringNotContainsString('Checked account', $body);
        $this->assertStringContainsString(__('qa_fixes.notice_wd_approved_body', [], $dealer->preferredLocale()), $body);
    }

    public function test_b4_a_settlement_month_is_written_in_words_with_bangla_digits(): void
    {
        $this->assertSame('মার্চ ২০২৭', CommissionSettlement::monthLabel('2027-03', 'bn'));
        $this->assertSame('March 2027', CommissionSettlement::monthLabel('2027-03', 'en'));
        $this->assertSame('not-a-month', CommissionSettlement::monthLabel('not-a-month', 'en'));
    }

    public function test_b7_the_office_cash_method_is_named_plainly(): void
    {
        $this->assertSame('অফিসে নগদ', __('portal.pay_channel_office', [], 'bn'));
    }

    public function test_b5_the_password_reset_mail_is_all_bangla_with_bangla_digits(): void
    {
        // The mail follows the person's own language (message templates); this dealer reads Bangla.
        $dealer = User::factory()->dealer()->create(['locale' => 'bn']);
        app()->setLocale('bn');
        $mail = (new ResetPassword('token'))->toMail($dealer);
        $html = (string) $mail->render();

        foreach (['Hello', 'Regards', 'All rights reserved', "If you're having trouble", 'minutes'] as $english) {
            $this->assertStringNotContainsString($english, $html);
        }
        $this->assertStringContainsString('৬০ মিনিট', $html);
        $this->assertStringContainsString('প্রিয়', $html);
        $this->assertStringContainsString('দয়া করে এই ইমেইলের উত্তর দেবেন না', $html);
    }

    public function test_b6_public_place_lists_show_bangla_names_on_bangla_pages(): void
    {
        $division = PlaceCode::factory()->division()->create(['name' => 'Dhaka']);
        $district = PlaceCode::factory()->district($division)->create(['name' => 'Dhaka']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['name' => 'Dhamrai']);

        $this->onPortal('public')->getJson('/partnership/places?level=division&lang=bn')->assertOk()->assertJsonFragment(['id' => $division->id, 'name' => 'ঢাকা']);
        $this->onPortal('public')->getJson('/partnership/places?level=upazila&parent_id='.$district->id.'&lang=bn')->assertOk()->assertJsonFragment(['id' => $upazila->id, 'name' => 'ধামরাই']);
        $this->onPortal('public')->getJson('/partnership/places?level=division&lang=en')->assertOk()->assertJsonFragment(['id' => $division->id, 'name' => 'Dhaka']);
    }

    public function test_b6_the_dealer_directory_pickers_use_bangla_names_on_bangla_pages(): void
    {
        $places = [
            1 => ['id' => 1, 'name' => 'Dhaka', 'level' => 'division', 'parent_id' => null],
            2 => ['id' => 2, 'name' => 'Dhaka', 'level' => 'district', 'parent_id' => 1],
            3 => ['id' => 3, 'name' => 'Dhamrai', 'level' => 'upazila', 'parent_id' => 2],
        ];
        app()->setLocale('bn');
        $bn = DirectoryAreaFilter::build($places, ['division' => '1', 'district' => '2']);
        $this->assertSame('ধামরাই', $bn['options']['upazila'][0]['name']);
        $this->assertSame('ঢাকা', $bn['options']['division'][0]['name']);
        app()->setLocale('en');
        $this->assertSame('Dhamrai', DirectoryAreaFilter::build($places, ['division' => '1', 'district' => '2'])['options']['upazila'][0]['name']);
    }
}
