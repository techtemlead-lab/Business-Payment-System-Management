<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\DealershipRequest;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EarningsBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function earning(Dealer $dealer, ?string $category, int $amount = 10000, ?string $date = null): LedgerEntry
    {
        return LedgerEntry::create(['dealer_id' => $dealer->id, 'earning_category' => $category, 'amount_minor' => $amount, 'kind' => 'earned', 'business_reference' => fake()->unique()->uuid(), 'reason' => 'Approved fixture earning', 'created_at' => $date ?? now()]);
    }

    public function test_dealer_report_scopes_categories_and_statement_to_own_account(): void
    {
        $dealer = Dealer::factory()->create();
        $other = Dealer::factory()->create(['code' => 'OTHER']);
        $this->earning($dealer, 'purchase_commission', 30000);
        $this->earning($dealer, 'dealer_salary', 5000);
        $this->earning($other, 'dealer_salary', 999999);
        $this->get('/rewards/earnings')->assertRedirect('/login');
        $this->actingAs($dealer->user)->get('/rewards/earnings?code=OTHER&category=dealer_salary')->assertInertia(fn (Assert $page) => $page->component('Earnings')->where('dealer.code', $dealer->code)->where('companyShareMinor', null)->where('categories.0.net_minor', 30000)->where('categories.4.net_minor', 5000)->where('categories.2.status', 'Not eligible for new awards')->has('entries.data', 1)->where('entries.data.0.amount_minor', 5000));
    }

    public function test_company_report_separates_income_from_dealer_earnings_and_can_filter_account(): void
    {
        $dealer = Dealer::factory()->create(['code' => 'SELECTED']);
        $other = Dealer::factory()->create();
        $this->earning($dealer, 'referral_bonus', 6000);
        $this->earning($other, 'purchase_commission', 8000);
        Purchase::factory()->create(['dealer_id' => $dealer->id, 'calculation' => ['preview_only' => false]]);
        Purchase::factory()->create(['dealer_id' => $dealer->id, 'status' => 'cancelled', 'calculation' => ['preview_only' => false]]);
        Purchase::factory()->create(['dealer_id' => $dealer->id, 'calculation' => ['preview_only' => true]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/rewards/earnings?code=SELECTED')->assertInertia(fn (Assert $page) => $page->where('companyShareMinor', 22500)->where('categories.0.net_minor', 0)->where('categories.1.net_minor', 6000)->has('entries.data', 1));
        $this->get('/rewards/earnings')->assertInertia(fn (Assert $page) => $page->where('categories.0.net_minor', 8000)->where('categories.1.net_minor', 6000)->has('entries.data', 2));
    }

    public function test_legacy_purchase_earnings_and_reversals_are_classified_without_mutating_history(): void
    {
        $dealer = Dealer::factory()->create();
        $earning = $this->earning($dealer, null, 37500);
        Purchase::factory()->create(['dealer_id' => $dealer->id, 'ledger_entry_id' => $earning->id]);
        $this->earning($dealer, null, 500);
        app(LedgerService::class)->reverse($earning->id, 'legacy-reversal', 'Correction to old purchase');
        $this->actingAs($dealer->user)->get('/rewards/earnings')->assertInertia(fn (Assert $page) => $page->where('categories.0.gross_minor', 37500)->where('categories.0.reversed_minor', 37500)->where('categories.0.net_minor', 0)->where('categories.10.net_minor', 500)->where('balances.earned_minor', 500));
        $this->assertNull($earning->fresh()->earning_category);
    }

    public function test_area_commission_requires_approved_area_type_and_history_survives_type_changes(): void
    {
        $regular = Dealer::factory()->create(['level' => 'Divisional']);
        try {
            $this->earning($regular, 'area_dealer_commission');
            $this->fail('Regular dealer received area commission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('earning_category', $exception->errors());
        }
        $area = Dealer::factory()->areaDealer()->create(['level' => 'Union']);
        $earning = $this->earning($area, 'area_dealer_commission');
        $area->update(['dealer_type' => 'dealer']);
        $reversal = app(LedgerService::class)->reverse($earning->id, 'area-reversal', 'Correct prior approved reward');
        $this->assertSame('area_dealer_commission', $reversal->earning_category);
        $this->actingAs($area->user)->get('/rewards/earnings')->assertInertia(fn (Assert $page) => $page->where('categories.2.gross_minor', 10000)->where('categories.2.reversed_minor', 10000)->where('categories.2.net_minor', 0));
    }

    public function test_requested_type_is_reviewed_and_does_not_automatically_promote_a_dealer(): void
    {
        $this->post('/request-dealership', ['name' => 'Applicant', 'email' => 'applicant@example.test', 'mobile' => '+8801712345678', 'business_name' => 'Business', 'business_address' => 'Dhaka', 'level' => 'District', 'division' => 'Dhaka', 'district' => 'Dhaka', 'consent' => true, 'dealer_type' => 'area_dealer'])->assertRedirect('/request-dealership');
        $application = DealershipRequest::firstOrFail();
        $this->assertSame('area_dealer', $application->dealer_type);
        $this->assertDatabaseCount('dealers', 0);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/dealers', ['name' => 'Applicant', 'email' => 'applicant@example.test', 'code' => 'NEW-DEALER', 'level' => 'District', 'division' => 'Dhaka', 'district' => 'Dhaka', 'dealer_type' => 'dealer', 'application_id' => $application->id, 'password' => 'Secure-Dealer-2026!'])->assertRedirect('/dealers');
        $this->assertDatabaseHas('dealers', ['code' => 'NEW-DEALER', 'dealer_type' => 'dealer', 'status' => 'pending']);
    }

    public function test_only_company_can_change_type_with_password_confirmation_and_audit(): void
    {
        $dealer = Dealer::factory()->create();
        $this->actingAs($dealer->user)->patch('/dealers/'.$dealer->id, ['dealer_type' => 'area_dealer'])->assertSessionHasErrors('dealer_type');
        $data = ['name' => $dealer->user->name, 'email' => $dealer->user->email, 'division' => 'Dhaka', 'district' => 'Dhaka', 'level' => 'District', 'dealer_type' => 'area_dealer'];
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch('/dealers/'.$dealer->id, $data)->assertSessionHasErrors('password');
        $this->patch('/dealers/'.$dealer->id, [...$data, 'password' => 'password'])->assertRedirect();
        $this->assertSame('area_dealer', $dealer->fresh()->dealer_type);
        $this->assertDatabaseHas('audit_events', ['action' => 'dealer.type_changed', 'subject_id' => $dealer->id]);
        $this->get('/dealers?dealer_type=area_dealer')->assertInertia(fn (Assert $page) => $page->has('dealers.data', 1)->where('dealers.data.0.dealer_type', 'area_dealer'));
        $this->get('/dealers?dealer_type=dealer')->assertInertia(fn (Assert $page) => $page->has('dealers.data', 0));
    }

    public function test_date_filters_use_bangladesh_day_boundaries_and_allow_to_date_only(): void
    {
        $dealer = Dealer::factory()->create();
        $this->earning($dealer, 'dealer_salary', 100, '2026-10-10 17:59:59');
        $this->earning($dealer, 'dealer_salary', 200, '2026-10-10 18:00:00');
        $this->earning($dealer, 'dealer_salary', 300, '2026-10-11 17:59:59');
        $this->earning($dealer, 'dealer_salary', 400, '2026-10-11 18:00:00');
        $this->actingAs($dealer->user)->get('/rewards/earnings?from=2026-10-11&to=2026-10-11')->assertInertia(fn (Assert $page) => $page->where('categories.4.net_minor', 500)->has('entries.data', 2));
        $this->get('/rewards/earnings?to=2026-10-11')->assertInertia(fn (Assert $page) => $page->where('categories.4.net_minor', 600)->has('entries.data', 3));
        $this->get('/rewards/earnings?from=2026-10-12&to=2026-10-10')->assertSessionHasErrors('to');
        $this->get('/rewards/earnings?category=unsupported')->assertSessionHasErrors('category');
    }

    public function test_purchase_posting_and_cancellation_keep_category_and_wallet_totals_consistent(): void
    {
        $dealer = Dealer::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/purchases', ['dealer_code' => $dealer->code, 'reference' => 'CATEGORY-TEST', 'product_name' => 'Test product', 'quantity' => 1, 'unit_mrp' => '1000', 'amount_paid' => '800', 'purchased_on' => now()->toDateString(), 'password' => 'password'])->assertRedirect();
        $purchase = Purchase::firstOrFail();
        $this->assertDatabaseHas('ledger_entries', ['id' => $purchase->ledger_entry_id, 'earning_category' => 'purchase_commission', 'amount_minor' => 50000]);
        $this->post('/purchases/'.$purchase->id.'/cancel', ['reason' => 'Cancel category test purchase', 'password' => 'password'])->assertRedirect();
        $this->actingAs($dealer->user)->get('/rewards/earnings')->assertInertia(fn (Assert $page) => $page->where('categories.0.gross_minor', 50000)->where('categories.0.reversed_minor', 50000)->where('categories.0.net_minor', 0)->where('balances.earned_minor', 0));
    }
}
