<?php

namespace Tests\Feature;

use App\Domain\Clinic\ClinicCashDeposits;
use App\Domain\Clinic\ClinicSaleVoids;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockService;
use App\Models\CompanyBranch;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClinicSaleVoidAndCashDepositTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{0: User, 1: CompanyBranch, 2: Prescription, 3: Product} */
    private function soldPrescription(bool $dispense = true): array
    {
        $owner = User::factory()->staff('super_admin')->create();
        $branch = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id]);
        $product = Product::factory()->create(['mrp' => '200.00', 'stock' => 0]);
        app(StockService::class)->adjust($owner, [
            'product_id' => $product->id, 'quantity' => 10, 'kind' => BranchOperations::KindProductIn,
            'reason_code' => 'supplier_receipt', 'reason' => 'Opening stock', 'reference' => 'rx-void-in-'.$product->id, 'branch_id' => $branch->id,
        ]);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => '2026-11-08', 'lines' => [['product_id' => $product->id, 'unit_price' => '150', 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $prescription = Prescription::query()->latest('id')->firstOrFail();
        if ($dispense) {
            $this->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $branch->id])->assertSessionHasNoErrors();
        }

        return [$owner, $branch, $prescription, $product];
    }

    private function accountNet(string $account): float
    {
        return (float) DB::table('journal_lines')->where('account', $account)->selectRaw('COALESCE(SUM(debit - credit), 0) AS net')->value('net');
    }

    public function test_dispensing_waits_for_the_money_posting_switch(): void
    {
        [$owner, $branch, $prescription] = $this->soldPrescription(false);
        $this->actingAs($owner)->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $branch->id])->assertSessionHasErrors('policy');
        $this->assertSame(Prescription::StatusIssued, $prescription->fresh()->status);
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_a_dispensed_sale_is_taken_back_once_with_a_reversing_journal_and_stock_back_in_its_lot(): void
    {
        $this->enableFinancialPosting();
        [$owner, $branch, $prescription, $product] = $this->soldPrescription();
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(18000, app(CompanyFunds::class)->balances()['clinic']['money_in']);

        $this->actingAs($owner)->post('/commands/prescriptions/'.$prescription->id.'/void-dispense', ['reason' => 'No'])->assertSessionHasErrors('reason');
        $this->post('/commands/prescriptions/'.$prescription->id.'/void-dispense', ['reason' => 'Patient returned the medicines'])->assertSessionHasNoErrors();
        $this->post('/commands/prescriptions/'.$prescription->id.'/void-dispense', ['reason' => 'Patient returned the medicines'])->assertSessionHasErrors('prescription');

        $this->assertSame(Prescription::StatusVoided, $prescription->fresh()->status);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'clinic.dispense_voided.'.$prescription->id)->count());
        foreach (['clinic_cash', 'clinic_fund', 'manufacturer_fund'] as $account) {
            $this->assertEquals(0, $this->accountNet($account), $account.' is back to zero');
        }
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
        // The lots are whole again and the clinic stock-out nets to nothing.
        $this->assertSame(0, (int) DB::table('stock_lot_movements')->where('prescription_id', $prescription->id)->sum('quantity'));
        $balances = app(CompanyFunds::class)->balances();
        $this->assertSame(0, $balances['clinic']['money_in']);
        $this->assertDatabaseHas('audit_events', ['action' => 'clinic.dispense_voided', 'subject' => (string) $prescription->id]);

        // A voided prescription is never dispensed again.
        $this->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $branch->id])->assertSessionHasErrors('prescription');
    }

    public function test_staff_who_dispensed_a_sale_cannot_take_it_back_alone(): void
    {
        $this->enableFinancialPosting();
        $clerk = User::factory()->create(['role' => 'finance', 'can_prescribe' => true]);
        $this->actingAs($clerk);
        $prescription = Prescription::factory()->create(['kind' => Prescription::KindClinic, 'status' => Prescription::StatusDispensed, 'dispensed_by' => $clerk->id]);
        $this->assertSame(422, $this->catchStatus(fn () => app(ClinicSaleVoids::class)->void($clerk, $prescription, 'Wrong patient chosen')));
        $this->assertSame(Prescription::StatusDispensed, $prescription->fresh()->status);
    }

    public function test_clinic_cash_is_deposited_once_and_never_beyond_what_the_clinic_holds(): void
    {
        $this->enableFinancialPosting();
        [$owner] = $this->soldPrescription();
        $this->assertSame(30000, app(ClinicCashDeposits::class)->balance());
        $payload = ['amount' => '300.01', 'deposited_on' => now()->timezone('Asia/Dhaka')->toDateString(), 'reference' => 'slip-1', 'note' => 'Carried to the bank', 'request_key' => (string) Str::uuid()];

        $this->actingAs($owner)->onPortal('sadmin')->postJson('/commands/operations/accounting/clinic-cash/deposits', $payload)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $payload['amount'] = '200.00';
        $id = $this->postJson('/commands/operations/accounting/clinic-cash/deposits', $payload)->assertOk()->json('data.id');
        $this->postJson('/commands/operations/accounting/clinic-cash/deposits', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/commands/operations/accounting/clinic-cash/deposits', [...$payload, 'request_key' => (string) Str::uuid(), 'amount' => '50.00'])->assertUnprocessable()->assertJsonValidationErrors('reference');

        $this->assertSame(10000, app(ClinicCashDeposits::class)->balance());
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'clinic.cash_deposit.'.$id)->count());
        $this->assertEquals(200.00, $this->accountNet('manual_collection_clearing'));
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
        $this->actingAs($owner)->onPortal('sadmin')->get('/operations/accounting')->assertOk()->assertInertia(fn ($page) => $page->where('clinicCash.balance', '100.00'));
    }

    private function catchStatus(\Closure $run): int
    {
        try {
            $run();
        } catch (ValidationException) {
            return 422;
        } catch (HttpException $exception) {
            return $exception->getStatusCode();
        }

        return 200;
    }
}
