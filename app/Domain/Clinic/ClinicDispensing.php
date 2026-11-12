<?php

namespace App\Domain\Clinic;

use App\Domain\Finance\Percentage;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockLots;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The owner-doctor's clinic: dispensing a clinic prescription from one branch.
 *
 * The owner sets the selling price on each line. 30% of the product MRP goes to the
 * manufacturer fund, the same percentage every dealer order carries (CommissionPolicy's
 * `company_mrp_percent`) and rounded the same way: once, on the whole MRP. The rest of the
 * owner's price goes to the clinic fund. No dealer commission, no dealer order.
 *
 * Journal (balanced): debit clinic_cash = price; credit manufacturer_fund = 30% of MRP;
 * credit clinic_fund = price - 30% of MRP (a debit when the owner allowed a lower price).
 */
final class ClinicDispensing
{
    /** Same as CommissionPolicy's company_mrp_percent. */
    public const ManufacturerMrpPercent = '30.00';

    /** Journal account for the manufacturer's 30% of MRP. */
    public const ManufacturerAccount = 'manufacturer_fund';

    public const ClinicFundAccount = 'clinic_fund';

    /** Money the clinic received, whichever way it was paid. */
    public const ClinicCashAccount = 'clinic_cash';

    /** Audit action written each time the owner confirms a sale below 30% of MRP. */
    public const BelowFloorOverrideAction = 'clinic.below_floor_override';

    /** The request field and error key for the owner's "yes, take the loss" answer. */
    public const ConfirmBelowFloorField = 'confirm_below_floor';

    public function __construct(private BranchOperations $branches) {}

    /**
     * The below-30% rule: nobody may sell below 30% of MRP except the owner (the Super Admin
     * account), and the owner must confirm the company's loss first. A confirm flag from
     * anyone else changes nothing.
     *
     * @param  list<string>  $items  The medicines priced too low.
     * @return bool True when the owner confirmed a sale below the floor.
     */
    public static function enforcePriceFloor(User $actor, array $items, bool $confirmed, string $errorField): bool
    {
        if ($items === []) {
            return false;
        }
        $names = implode(', ', $items);
        if (! PrescriptionAccess::isOwnerDoctor($actor)) {
            throw ValidationException::withMessages([$errorField => __('prescription.err_below_floor', ['items' => $names, 'percent' => '30'])]);
        }
        if (! $confirmed) {
            throw ValidationException::withMessages([self::ConfirmBelowFloorField => __('prescription.confirm_below_floor', ['items' => $names])]);
        }

        return true;
    }

    /**
     * The manufacturer share and clinic fund for a clinic prescription, and the lines priced
     * below 30% of their MRP.
     *
     * @return array{price: int, mrp: int, manufacturer: int, clinic: int, below_floor: list<string>}
     */
    public function split(Prescription $prescription): array
    {
        $percent = Percentage::from(self::ManufacturerMrpPercent);
        $price = 0;
        $mrp = 0;
        $below = [];
        foreach ($prescription->lines as $line) {
            $lineMrp = Money::cents((string) ($line->mrp ?? '0.00')) * (int) $line->quantity;
            $linePrice = Money::cents((string) $line->line_total);
            if ($linePrice < $percent->of($lineMrp, 'half_up')) {
                $below[] = $line->medicine_name;
            }
            $price += $linePrice;
            $mrp += $lineMrp;
        }
        $manufacturer = $percent->of($mrp, 'half_up');
        if ($below === [] && $price < $manufacturer) {
            $below = $prescription->lines->pluck('medicine_name')->all();
        }

        return ['price' => $price, 'mrp' => $mrp, 'manufacturer' => $manufacturer, 'clinic' => $price - $manufacturer, 'below_floor' => $below];
    }

    public function dispense(
        User $actor,
        Prescription $prescription,
        int $branchId,
        string $paymentMethod = Prescription::PaymentCash,
        ?string $paymentReference = null,
        bool $confirmBelowFloor = false,
    ): Prescription {
        abort_unless(PrescriptionAccess::usesCompanyStock($actor), 403);
        abort_unless($prescription->kind === Prescription::KindClinic, 422, __('prescription.err_not_clinic'));
        abort_unless(in_array($paymentMethod, Prescription::PaymentMethods, true), 422);
        $branch = $this->branches->resolveWritableBranch($actor, $branchId);
        $reference = filled($paymentReference) ? trim($paymentReference) : null;

        return DB::transaction(function () use ($actor, $prescription, $branch, $paymentMethod, $reference, $confirmBelowFloor): Prescription {
            $locked = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === Prescription::StatusDispensed) {
                throw ValidationException::withMessages(['prescription' => __('prescription.err_already_dispensed')]);
            }
            $locked->load('lines');
            if ($locked->lines->isEmpty()) {
                // A prescription of tests, foods or advice only has no stock to take out and no sale to post.
                throw ValidationException::withMessages(['prescription' => __('prescription.err_nothing_to_dispense')]);
            }
            $split = $this->split($locked);
            $overridden = self::enforcePriceFloor($actor, $split['below_floor'], $confirmBelowFloor, 'prices');
            foreach ($locked->lines as $line) {
                $this->branches->adjustStockForClinicDispense($actor, [
                    'product_id' => (int) $line->product_id,
                    'quantity' => (int) $line->quantity,
                    'kind' => BranchOperations::KindProductOut,
                    'reason_code' => 'other',
                    'reason' => 'Clinic dispense '.$locked->number,
                    'reference' => 'clinic-'.$locked->number.'-'.$line->id,
                    'branch_id' => $branch->id,
                    'lot_context' => ['kind' => StockLots::KindClinicDispense, 'prescription_id' => $locked->id],
                ]);
            }
            $clinic = $split['clinic'];
            $this->journal('clinic.dispense.'.$locked->id, __('prescription.jr_dispensed', ['number' => $locked->number]), $actor, [
                ['account' => self::ClinicCashAccount, 'debit' => $split['price'], 'credit' => 0],
                ['account' => self::ManufacturerAccount, 'debit' => 0, 'credit' => $split['manufacturer']],
                ['account' => self::ClinicFundAccount, 'debit' => $clinic < 0 ? -$clinic : 0, 'credit' => max(0, $clinic)],
            ]);
            $locked->forceFill([
                'status' => Prescription::StatusDispensed,
                'branch_id' => $branch->id,
                'mrp_total' => Money::decimal($split['mrp']),
                'manufacturer_share' => Money::decimal($split['manufacturer']),
                // Stored as the signed amount the clinic fund moved; negative only after the owner's override.
                'clinic_fund' => $clinic < 0 ? '-'.Money::decimal(-$clinic) : Money::decimal($clinic),
                'dispensed_by' => $actor->id,
                'dispensed_at' => now(),
                'payment_method' => $paymentMethod,
                'payment_reference' => $reference,
            ])->save();
            Audit::record($actor->id, 'clinic.dispensed', (string) $locked->id, [
                'number' => $locked->number,
                'branch_id' => $branch->id,
                'price' => Money::decimal($split['price']),
                'manufacturer_share' => Money::decimal($split['manufacturer']),
                'clinic_fund' => $locked->clinic_fund,
                'below_floor' => $split['below_floor'],
                'payment_method' => $paymentMethod,
                'payment_reference' => $reference,
            ]);
            if ($overridden) {
                Audit::record($actor->id, self::BelowFloorOverrideAction, (string) $locked->id, [
                    'stage' => 'dispensed',
                    'number' => $locked->number,
                    'items' => $split['below_floor'],
                    'price' => Money::decimal($split['price']),
                    'mrp' => Money::decimal($split['mrp']),
                    'manufacturer_share' => Money::decimal($split['manufacturer']),
                    'clinic_fund' => $locked->clinic_fund,
                ]);
            }

            return $locked->refresh();
        });
    }

    /**
     * Dispensed clinic sales, the manufacturer share and the clinic fund for a period
     * (Dhaka dates, both ends included), with one row per day and one per payment method.
     *
     * @return array{from: string, to: string, count: int, sales: string, manufacturer_share: string, clinic_fund: string, days: list<array{day: string, count: int, sales: string, manufacturer_share: string, clinic_fund: string}>, by_method: list<array{method: string, count: int, sales: string}>}
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfDay()->utc();
        $end = $to->endOfDay()->utc();
        $rows = DB::table('prescriptions')
            ->where('kind', Prescription::KindClinic)
            ->where('status', Prescription::StatusDispensed)
            ->whereBetween('dispensed_at', [$start, $end])
            ->selectRaw("to_char((dispensed_at AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Dhaka', 'YYYY-MM-DD') as day, count(*) as count, sum(total) as sales, sum(manufacturer_share) as manufacturer_share, sum(clinic_fund) as clinic_fund")
            ->groupBy('day')
            ->orderBy('day')
            ->get();
        $days = $rows->map(fn (object $row): array => [
            'day' => (string) $row->day,
            'count' => (int) $row->count,
            'sales' => number_format((float) $row->sales, 2, '.', ''),
            'manufacturer_share' => number_format((float) $row->manufacturer_share, 2, '.', ''),
            'clinic_fund' => number_format((float) $row->clinic_fund, 2, '.', ''),
        ])->values()->all();
        $byMethod = DB::table('prescriptions')
            ->where('kind', Prescription::KindClinic)
            ->where('status', Prescription::StatusDispensed)
            ->whereBetween('dispensed_at', [$start, $end])
            ->selectRaw('payment_method as method, count(*) as count, sum(total) as sales')
            ->groupBy('payment_method')
            ->orderByDesc('sales')
            ->get()
            ->map(fn (object $row): array => [
                'method' => (string) $row->method,
                'count' => (int) $row->count,
                'sales' => number_format((float) $row->sales, 2, '.', ''),
            ])->values()->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => (int) array_sum(array_column($days, 'count')),
            'sales' => number_format(array_sum(array_map('floatval', array_column($days, 'sales'))), 2, '.', ''),
            'manufacturer_share' => number_format(array_sum(array_map('floatval', array_column($days, 'manufacturer_share'))), 2, '.', ''),
            'clinic_fund' => number_format(array_sum(array_map('floatval', array_column($days, 'clinic_fund'))), 2, '.', ''),
            'days' => $days,
            'by_method' => $byMethod,
        ];
    }

    /**
     * Writes a balanced journal entry; the ledger is append-only.
     *
     * @param  list<array{account: string, debit: int, credit: int}>  $lines
     */
    private function journal(string $eventKey, string $description, User $actor, array $lines): void
    {
        if (array_sum(array_column($lines, 'debit')) !== array_sum(array_column($lines, 'credit'))) {
            throw new \LogicException('Unbalanced clinic journal.');
        }
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => $eventKey, 'description' => mb_substr($description, 0, 255), 'actor_id' => $actor->id, 'created_at' => now()]);
        foreach ($lines as $line) {
            if ($line['debit'] > 0 || $line['credit'] > 0) {
                DB::table('journal_lines')->insert([
                    'entry_id' => $id,
                    'user_id' => null,
                    'account' => $line['account'],
                    'debit' => Money::decimal($line['debit']),
                    'credit' => Money::decimal($line['credit']),
                ]);
            }
        }
    }
}
