<?php

namespace App\Domain\Clinic;

use App\Domain\Finance\Percentage;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockService;
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
 * manufacturer fund, the same company share every dealer order carries
 * (CommissionPolicy's `company_mrp_percent`, stored as `company_share`). The rest of the
 * owner's price goes to the clinic fund. No dealer commission, no dealer order.
 *
 * Journal (balanced): debit clinic_cash = price; credit company_share = 30% of MRP;
 * credit clinic_fund = price - 30% of MRP (a debit when the owner allowed a lower price).
 */
final class ClinicDispensing
{
    /** Same as CommissionPolicy's company_mrp_percent. */
    public const ManufacturerMrpPercent = '30.00';

    /** Journal account for the manufacturer's 30% of MRP, shared with the company share. */
    public const ManufacturerAccount = 'company_share';

    public const ClinicFundAccount = 'clinic_fund';

    public const ClinicCashAccount = 'clinic_cash';

    public const PriceFloorKey = 'prescription_price_floor';

    /** What happens when the owner's price is below 30% of MRP. */
    public const PriceFloorModes = ['block', 'warn'];

    public function __construct(private StockService $stock, private BranchOperations $branches) {}

    /** @return array{mode: string, version: int} */
    public function priceFloor(): array
    {
        $row = DB::table('business_settings')->where('key', self::PriceFloorKey)->first();
        $stored = $row ? json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR) : [];
        $mode = in_array($stored['mode'] ?? null, self::PriceFloorModes, true) ? $stored['mode'] : 'block';

        return ['mode' => $mode, 'version' => (int) ($row->version ?? 0)];
    }

    public function savePriceFloor(User $actor, string $mode): void
    {
        abort_unless(PrescriptionAccess::isOwnerDoctor($actor), 403);
        abort_unless(in_array($mode, self::PriceFloorModes, true), 422);
        $before = $this->priceFloor();
        $stored = DB::table('business_settings')->where('key', self::PriceFloorKey)->first();
        DB::table('business_settings')->updateOrInsert(['key' => self::PriceFloorKey], [
            'value' => json_encode(['mode' => $mode], JSON_THROW_ON_ERROR),
            'version' => $before['version'] + 1,
            'updated_by' => $actor->id,
            'created_at' => $stored->created_at ?? now(),
            'updated_at' => now(),
        ]);
        Audit::record($actor->id, 'clinic.price_floor_changed', self::PriceFloorKey, ['before' => $before['mode'], 'after' => $mode]);
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
        $manufacturer = 0;
        $below = [];
        foreach ($prescription->lines as $line) {
            $lineMrp = Money::cents((string) ($line->mrp ?? '0.00')) * (int) $line->quantity;
            $lineShare = $percent->of($lineMrp, 'half_up');
            $linePrice = Money::cents((string) $line->line_total);
            if ($linePrice < $lineShare) {
                $below[] = $line->medicine_name;
            }
            $price += $linePrice;
            $mrp += $lineMrp;
            $manufacturer += $lineShare;
        }

        return ['price' => $price, 'mrp' => $mrp, 'manufacturer' => $manufacturer, 'clinic' => $price - $manufacturer, 'below_floor' => $below];
    }

    public function dispense(User $actor, Prescription $prescription, int $branchId): Prescription
    {
        abort_unless(PrescriptionAccess::isOwnerDoctor($actor) && PrescriptionAccess::enabled(), 403);
        abort_unless($prescription->kind === Prescription::KindClinic, 422, __('prescription.err_not_clinic'));
        $branch = $this->branches->resolveWritableBranch($actor, $branchId);

        return DB::transaction(function () use ($actor, $prescription, $branch): Prescription {
            $locked = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === Prescription::StatusDispensed) {
                throw ValidationException::withMessages(['prescription' => __('prescription.err_already_dispensed')]);
            }
            $locked->load('lines');
            $split = $this->split($locked);
            if ($split['below_floor'] !== [] && $this->priceFloor()['mode'] === 'block') {
                throw ValidationException::withMessages(['prices' => __('prescription.err_below_floor', [
                    'items' => implode(', ', $split['below_floor']),
                    'percent' => '30',
                ])]);
            }
            foreach ($locked->lines as $line) {
                $this->stock->adjust($actor, [
                    'product_id' => (int) $line->product_id,
                    'quantity' => (int) $line->quantity,
                    'kind' => BranchOperations::KindProductOut,
                    'reason_code' => 'other',
                    'reason' => 'Clinic dispense '.$locked->number,
                    'reference' => 'clinic-'.$locked->number.'-'.$line->id,
                    'branch_id' => $branch->id,
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
                // Stored as the signed amount the clinic fund moved; negative only in warn mode.
                'clinic_fund' => $clinic < 0 ? '-'.Money::decimal(-$clinic) : Money::decimal($clinic),
                'dispensed_by' => $actor->id,
                'dispensed_at' => now(),
            ])->save();
            Audit::record($actor->id, 'clinic.dispensed', (string) $locked->id, [
                'number' => $locked->number,
                'branch_id' => $branch->id,
                'price' => Money::decimal($split['price']),
                'manufacturer_share' => Money::decimal($split['manufacturer']),
                'clinic_fund' => $locked->clinic_fund,
                'below_floor' => $split['below_floor'],
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Dispensed clinic sales, the manufacturer share and the clinic fund for a period
     * (Dhaka dates, both ends included), with one row per day.
     *
     * @return array{from: string, to: string, count: int, sales: string, manufacturer_share: string, clinic_fund: string, days: list<array{day: string, count: int, sales: string, manufacturer_share: string, clinic_fund: string}>}
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

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => (int) array_sum(array_column($days, 'count')),
            'sales' => number_format(array_sum(array_map('floatval', array_column($days, 'sales'))), 2, '.', ''),
            'manufacturer_share' => number_format(array_sum(array_map('floatval', array_column($days, 'manufacturer_share'))), 2, '.', ''),
            'clinic_fund' => number_format(array_sum(array_map('floatval', array_column($days, 'clinic_fund'))), 2, '.', ''),
            'days' => $days,
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
