<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockService;
use App\Models\CompanyBranch;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Bugs found in the pre-go-live review of the prescription module.
 */
class PrescriptionReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_partner_cannot_open_a_print_page_after_prescribing_is_turned_off(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/accounts/'.$dealer->id.'/prescriber', ['registration_type' => 'bmdc', 'registration_number' => 'A-12345'])
            ->assertRedirect();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
        $printPath = '/prescriptions/'.$prescription->id.'/print';
        $this->actingAs($dealer->fresh())->onPortal('dealer')->get($printPath)->assertOk();

        $this->actingAs($owner)->onPortal('sadmin')
            ->delete('/commands/accounts/'.$dealer->id.'/prescriber', ['reason' => 'Licence expired'])
            ->assertRedirect();

        $this->actingAs($dealer->fresh())->onPortal('dealer')->get($printPath)->assertForbidden();
        $this->actingAs($owner)->onPortal('sadmin')->get($printPath)->assertOk();
    }

    public function test_the_print_button_script_carries_the_nonce_the_page_policy_allows(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => true]);
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id]);
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);

        $response = $this->actingAs($dealer)->onPortal('dealer')->get('/prescriptions/'.$prescription->id.'/print');

        $response->assertOk();
        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $policyNonce));
        $response->assertSee('<script nonce="'.$policyNonce[1].'">', false);
    }

    public function test_the_thirty_percent_is_credited_to_the_manufacturer_fund_account(): void
    {
        [$owner, $branch, $patient] = $this->clinic();
        $product = $this->stockedProduct($owner, $branch, '200.00');
        $this->writePrescription($owner, $patient, [['product_id' => $product->id, 'unit_price' => '150', 'quantity' => 1]])
            ->assertSessionHasNoErrors();
        $prescription = Prescription::query()->firstOrFail();

        $this->dispense($owner, $prescription, $branch)->assertSessionHasNoErrors();

        $this->assertSame([
            'clinic_cash' => ['150.00', '0.00'],
            'clinic_fund' => ['0.00', '90.00'],
            'manufacturer_fund' => ['0.00', '60.00'],
        ], $this->journalFor($prescription));
    }

    public function test_the_thirty_percent_is_rounded_once_on_the_whole_mrp_like_a_dealer_order(): void
    {
        [$owner, $branch, $patient] = $this->clinic();
        $lines = [];
        foreach (range(1, 3) as $ignored) {
            $lines[] = ['product_id' => $this->stockedProduct($owner, $branch, '10.05')->id, 'unit_price' => '10.05', 'quantity' => 1];
        }
        $this->writePrescription($owner, $patient, $lines)->assertSessionHasNoErrors();
        $prescription = Prescription::query()->firstOrFail();

        $this->dispense($owner, $prescription, $branch)->assertSessionHasNoErrors();

        // 30% of the 30.15 MRP is 9.045, so 9.05. Rounding each 3.015 line first would give 9.06.
        $prescription->refresh();
        $this->assertSame('30.15', $prescription->mrp_total);
        $this->assertSame('9.05', $prescription->manufacturer_share);
        $this->assertSame('21.10', $prescription->clinic_fund);
        $this->assertSame([
            'clinic_cash' => ['30.15', '0.00'],
            'clinic_fund' => ['0.00', '21.10'],
            'manufacturer_fund' => ['0.00', '9.05'],
        ], $this->journalFor($prescription));
    }

    public function test_the_block_holds_when_rounding_the_whole_mrp_would_take_the_clinic_fund_below_zero(): void
    {
        [$owner, $branch, $patient] = $this->clinic();
        $lines = [];
        foreach (range(1, 3) as $ignored) {
            // Each line just clears its own floor (30% of 0.04 rounds to 0.01), but 30% of the whole 0.12 rounds to 0.04.
            $lines[] = ['product_id' => $this->stockedProduct($owner, $branch, '0.04')->id, 'unit_price' => '0.01', 'quantity' => 1];
        }

        $this->writePrescription($owner, $patient, $lines)->assertSessionHasErrors('lines');
        $this->assertSame(0, Prescription::query()->count());

        $this->setPriceFloor($owner, 'warn');
        $this->writePrescription($owner, $patient, $lines)->assertSessionHasNoErrors();
        $prescription = Prescription::query()->firstOrFail();
        $this->setPriceFloor($owner, 'block');

        $this->dispense($owner, $prescription, $branch)->assertSessionHasErrors('prices');
        $this->assertSame(Prescription::StatusIssued, $prescription->fresh()->status);
        $this->assertFalse(DB::table('journal_entries')->where('event_key', 'clinic.dispense.'.$prescription->id)->exists());
    }

    /** @return array{0: User, 1: CompanyBranch, 2: PrescriptionPatient} */
    private function clinic(): array
    {
        $owner = User::factory()->staff('super_admin')->create();
        $branch = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id]);

        return [$owner, $branch, $patient];
    }

    private function stockedProduct(User $owner, CompanyBranch $branch, string $mrp): Product
    {
        $product = Product::factory()->create(['mrp' => $mrp, 'stock' => 0]);
        app(StockService::class)->adjust($owner, [
            'product_id' => $product->id, 'quantity' => 10, 'kind' => BranchOperations::KindProductIn,
            'reason_code' => 'supplier_receipt', 'reason' => 'Opening stock', 'reference' => 'rx-review-in-'.$product->id, 'branch_id' => $branch->id,
        ]);

        return $product;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return TestResponse<Response>
     */
    private function writePrescription(User $owner, PrescriptionPatient $patient, array $lines): TestResponse
    {
        return $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => '2026-11-08', 'lines' => $lines,
        ]);
    }

    /** @return TestResponse<Response> */
    private function dispense(User $owner, Prescription $prescription, CompanyBranch $branch): TestResponse
    {
        return $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/prescriptions/'.$prescription->id.'/dispense', ['branch_id' => $branch->id]);
    }

    private function setPriceFloor(User $owner, string $mode): void
    {
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions/price-floor', ['mode' => $mode])->assertRedirect();
    }

    /** @return array<string, array{0: string, 1: string}> Debit and credit for each account, by account name. */
    private function journalFor(Prescription $prescription): array
    {
        $entryId = DB::table('journal_entries')->where('event_key', 'clinic.dispense.'.$prescription->id)->value('id');

        return DB::table('journal_lines')->where('entry_id', $entryId)->orderBy('account')->get()
            ->mapWithKeys(fn (object $line): array => [$line->account => [number_format((float) $line->debit, 2, '.', ''), number_format((float) $line->credit, 2, '.', '')]])
            ->all();
    }
}
