<?php

namespace App\Domain\Clinic;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockLots;
use App\Domain\Security\SelfApproval;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Taking back a clinic sale: the patient gets the money back and the medicines go back into the
 * lots they left from. The sale's own journal entry is posted again with debit and credit swapped,
 * so clinic cash, the manufacturer share and the clinic fund return to where they were. The
 * prescription keeps its lines and is marked voided; every report counts dispensed sales only.
 *
 * Two people: whoever dispensed the sale cannot take it back alone; the owner may.
 */
final class ClinicSaleVoids
{
    public function __construct(private BranchOperations $branches) {}

    public function void(User $actor, Prescription $prescription, string $reason): Prescription
    {
        abort_unless(PrescriptionAccess::usesCompanyStock($actor), 403);
        abort_unless($prescription->kind === Prescription::KindClinic, 422, __('prescription.err_not_clinic'));
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($actor, $prescription, $reason): Prescription {
            CommissionWallet::lockShared();
            $locked = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Prescription::StatusDispensed) {
                throw ValidationException::withMessages(['prescription' => __('prescription.err_void_not_dispensed')]);
            }
            $selfApproved = (int) $locked->dispensed_by === (int) $actor->id;
            if ($selfApproved) {
                app(SelfApproval::class)->finishOwnStep($actor, 'prescription', __('prescription.err_void_own_sale'));
            }
            $sale = DB::table('journal_entries')->where('event_key', 'clinic.dispense.'.$locked->id)->first();
            if ($sale === null) {
                throw ValidationException::withMessages(['prescription' => __('prescription.err_void_no_journal')]);
            }
            $this->reverseJournal($sale->id, 'clinic.dispense_voided.'.$locked->id, __('prescription.jr_dispense_voided', ['number' => $locked->number]), $actor);
            $this->returnStock($actor, $locked);
            $locked->forceFill(['status' => Prescription::StatusVoided])->save();
            Audit::record($actor->id, 'clinic.dispense_voided', (string) $locked->id, [
                'number' => $locked->number,
                'branch_id' => $locked->branch_id,
                'total' => (string) $locked->total,
                'manufacturer_share' => (string) $locked->manufacturer_share,
                'clinic_fund' => (string) $locked->clinic_fund,
                'reason' => $reason,
                ...($selfApproved ? ['self_approved' => true] : []),
            ]);

            return $locked->refresh();
        });
    }

    /** Posts the sale's lines again with debit and credit swapped. The event key makes it happen once. */
    private function reverseJournal(string $saleEntryId, string $eventKey, string $description, User $actor): void
    {
        $lines = DB::table('journal_lines')->where('entry_id', $saleEntryId)->get(['user_id', 'account', 'debit', 'credit']);
        $debits = 0;
        $credits = 0;
        foreach ($lines as $line) {
            $debits += Money::cents((string) $line->credit);
            $credits += Money::cents((string) $line->debit);
        }
        if ($debits !== $credits) {
            throw new \LogicException('Unbalanced clinic reversal.');
        }
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => $eventKey, 'description' => mb_substr($description, 0, 255), 'actor_id' => $actor->id, 'created_at' => now()]);
        DB::table('journal_lines')->insert($lines->map(fn (object $line): array => [
            'entry_id' => $id, 'user_id' => $line->user_id, 'account' => $line->account, 'debit' => (string) $line->credit, 'credit' => (string) $line->debit,
        ])->all());
    }

    /** Each medicine goes back into the lots it left from, on the branch that sold it. */
    private function returnStock(User $actor, Prescription $prescription): void
    {
        foreach ($prescription->lines()->whereNotNull('product_id')->get() as $line) {
            $taken = DB::table('stock_lot_movements')
                ->where('reference', 'clinic-'.$prescription->number.'-'.$line->id)
                ->where('kind', StockLots::KindClinicDispense)->where('quantity', '<', 0)
                ->get(['lot_id', 'quantity']);
            $this->branches->adjustStockForClinicDispense($actor, [
                'product_id' => (int) $line->product_id,
                'quantity' => (int) $line->quantity,
                'kind' => BranchOperations::KindProductIn,
                'reason_code' => 'return',
                'reason' => 'Clinic sale taken back '.$prescription->number,
                'reference' => 'clinic-void-'.$prescription->number.'-'.$line->id,
                'branch_id' => (int) $prescription->branch_id,
                'return_lots' => $taken->map(fn (object $lot): array => ['lot_id' => (int) $lot->lot_id, 'quantity' => -(int) $lot->quantity])->values()->all(),
                // Same kind as the sale, so the clinic stock-out figures net back to zero.
                'lot_context' => ['kind' => StockLots::KindClinicDispense, 'prescription_id' => $prescription->id],
            ]);
        }
    }
}
