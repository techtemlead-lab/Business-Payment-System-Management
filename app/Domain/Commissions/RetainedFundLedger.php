<?php

namespace App\Domain\Commissions;

use App\Models\CommissionAllocation;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RetainedFundLedger
{
    public const BenefitsCategory = 'company_commission_benefits_fund';

    /** @return list<string> */
    public static function companyRetainedStatuses(): array
    {
        return ['unassigned_reserve', 'reserved', 'pending'];
    }

    /**
     * Detailed line items for company-retained commission amounts.
     *
     * @return array{
     *   unassigned_geographic: list<array<string, mixed>>,
     *   management_area: list<array<string, mixed>>,
     *   override_reserves: list<array<string, mixed>>,
     *   marketing_fund: list<array<string, mixed>>,
     *   car_bike_fund: list<array<string, mixed>>,
     *   benefits_fund: list<array<string, mixed>>,
     *   company_share_batches: list<array<string, mixed>>,
     *   totals: array<string, float>
     * }
     */
    public function detail(bool $includeOwnerBenefits = false, int $limit = 50): array
    {
        $groups = [
            'unassigned_geographic' => [],
            'management_area' => [],
            'override_reserves' => [],
            'marketing_fund' => [],
            'car_bike_fund' => [],
            'benefits_fund' => [],
            'company_share_batches' => [],
        ];

        $allocations = CommissionAllocation::query()
            ->with(['order:id,number,user_id,total,commission_policy_snapshot', 'order.user:id,name,dealer_code,division,district,role,profile'])
            ->where('status', '!=', 'reversed')
            ->where(function ($query): void {
                $query->where('status', 'unassigned_reserve')
                    ->orWhere('category', 'area_management')
                    ->orWhere('category', 'company_marketing_fund')
                    ->orWhere('category', 'car_bike_incentive_fund')
                    ->orWhere('category', self::BenefitsCategory)
                    ->orWhere('category', 'like', '%_override_reserve')
                    ->orWhere('category', 'like', '%_breakage');
            })
            ->latest('id')
            ->limit($limit * 3)
            ->get();

        foreach ($allocations as $allocation) {
            $row = $this->allocationRow($allocation);
            if ($allocation->status === 'unassigned_reserve' || str_starts_with((string) $allocation->category, 'area_')) {
                if ($allocation->status === 'unassigned_reserve') {
                    $groups['unassigned_geographic'][] = $row;
                } elseif ($allocation->category === 'area_management') {
                    $groups['management_area'][] = $row;
                }
            } elseif ($allocation->category === 'company_marketing_fund') {
                $groups['marketing_fund'][] = $row;
            } elseif ($allocation->category === 'car_bike_incentive_fund') {
                $groups['car_bike_fund'][] = $row;
            } elseif ($allocation->category === self::BenefitsCategory) {
                if ($includeOwnerBenefits) {
                    $groups['benefits_fund'][] = $row;
                }
            } elseif (str_contains((string) $allocation->category, 'override_reserve') || str_contains((string) $allocation->category, 'breakage')) {
                $groups['override_reserves'][] = $row;
            }
        }

        foreach ($groups as $key => $rows) {
            $groups[$key] = array_slice($rows, 0, $limit);
        }

        $groups['company_share_batches'] = $this->companyShareRows($limit);
        $groups['totals'] = [
            'unassigned_geographic' => $this->sumAmount($groups['unassigned_geographic']),
            'management_area' => $this->sumAmount($groups['management_area']),
            'override_reserves' => $this->sumAmount($groups['override_reserves']),
            'marketing_fund' => $this->sumAmount($groups['marketing_fund']),
            'car_bike_fund' => $this->sumAmount($groups['car_bike_fund']),
            'benefits_fund' => $includeOwnerBenefits ? $this->sumAmount($groups['benefits_fund']) : 0.0,
            'company_share_batches' => $this->sumAmount($groups['company_share_batches'], 'company_share'),
        ];

        return $groups;
    }

    /**
     * Enrich a benefits-fund allocation for the owner ledger page.
     *
     * @return array<string, mixed>
     */
    public function benefitsEntry(CommissionAllocation $allocation): array
    {
        $row = $this->allocationRow($allocation);
        $batch = DB::table('commission_batches')->where('order_id', $allocation->order_id)->first();
        if ($batch) {
            $cash = Money::cents((string) $batch->cash_basis);
            $refunded = Money::cents((string) $batch->refunded_amount);
            $factor = $cash > 0 ? ($cash - $refunded) / $cash : 0;
            $row['company_share'] = Money::decimal((int) round(Money::cents((string) $batch->company_share) * $factor));
            $row['total_allocated'] = Money::decimal((int) round(Money::cents((string) $batch->total_allocated) * $factor));
            $row['batch_unallocated'] = Money::decimal((int) round(Money::cents((string) $batch->unallocated) * $factor));
            $row['mrp_basis'] = (string) $batch->mrp_basis;
            $row['refunded_amount'] = (string) $batch->refunded_amount;
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function allocationRow(CommissionAllocation $allocation): array
    {
        $order = $allocation->order;
        $buyer = $order?->user;
        $snapshot = is_array($order?->commission_policy_snapshot) ? $order->commission_policy_snapshot : [];
        $geography = is_array($snapshot['geography'] ?? null) ? $snapshot['geography'] : [
            'division' => $buyer?->division,
            'district' => $buyer?->district,
            'upazila' => $buyer?->profile['upazila'] ?? null,
            'union' => $buyer?->profile['union'] ?? null,
        ];
        $metadata = is_array($allocation->metadata) ? $allocation->metadata : [];
        $level = str_starts_with((string) $allocation->category, 'area_')
            ? str_replace('area_', '', (string) $allocation->category)
            : null;

        return [
            'id' => $allocation->id,
            'fund' => $this->fundLabel($allocation),
            'category' => $allocation->category,
            'status' => $allocation->status,
            'amount' => (string) $allocation->amount,
            'original_amount' => (string) ($allocation->original_amount ?? $allocation->amount),
            'rate_percentage' => (string) $allocation->rate_percentage,
            'purchase_value' => (string) $allocation->cash_basis,
            'order_id' => $allocation->order_id,
            'order_number' => $order?->number,
            'buyer_id' => $buyer?->id,
            'buyer_name' => $buyer?->name,
            'buyer_code' => $buyer?->dealer_code,
            'buyer_role' => $buyer?->role,
            'division' => $geography['division'] ?? null,
            'district' => $geography['district'] ?? null,
            'upazila' => $geography['upazila'] ?? null,
            'union' => $geography['union'] ?? null,
            'area_level' => $level,
            'retained_by' => $metadata['retained_by'] ?? null,
            'reason' => $this->reason($allocation, $metadata, $level, $geography),
            'created_at' => optional($allocation->created_at)?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function companyShareRows(int $limit): array
    {
        $batches = DB::table('commission_batches')
            ->join('orders', 'orders.id', '=', 'commission_batches.order_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->orderByDesc('commission_batches.id')
            ->limit($limit)
            ->get([
                'commission_batches.id',
                'commission_batches.order_id',
                'commission_batches.cash_basis',
                'commission_batches.mrp_basis',
                'commission_batches.company_share',
                'commission_batches.total_allocated',
                'commission_batches.unallocated',
                'commission_batches.refunded_amount',
                'commission_batches.created_at',
                'orders.number as order_number',
                'users.id as buyer_id',
                'users.name as buyer_name',
                'users.dealer_code as buyer_code',
                'users.role as buyer_role',
                'users.division',
                'users.district',
            ]);

        return $batches->map(function ($batch): array {
            $cash = Money::cents((string) $batch->cash_basis);
            $refunded = Money::cents((string) $batch->refunded_amount);
            $factor = $cash > 0 ? ($cash - $refunded) / $cash : 0;
            $company = (int) round(Money::cents((string) $batch->company_share) * $factor);

            return [
                'id' => $batch->id,
                'fund' => 'Company operating share (30% of MRP)',
                'category' => 'company_share',
                'status' => 'retained',
                'amount' => Money::decimal($company),
                'company_share' => Money::decimal($company),
                'original_amount' => (string) $batch->company_share,
                'rate_percentage' => '30.00',
                'purchase_value' => (string) $batch->cash_basis,
                'mrp_basis' => (string) $batch->mrp_basis,
                'total_allocated' => (string) $batch->total_allocated,
                'batch_unallocated' => (string) $batch->unallocated,
                'refunded_amount' => (string) $batch->refunded_amount,
                'order_id' => $batch->order_id,
                'order_number' => $batch->order_number,
                'buyer_id' => $batch->buyer_id,
                'buyer_name' => $batch->buyer_name,
                'buyer_code' => $batch->buyer_code,
                'buyer_role' => $batch->buyer_role,
                'division' => $batch->division,
                'district' => $batch->district,
                'upazila' => null,
                'union' => null,
                'area_level' => null,
                'retained_by' => 'company_operating_reserve',
                'reason' => 'Company share retained from purchase to cover product cost and operating margin.',
                'created_at' => $batch->created_at,
            ];
        })->all();
    }

    /** @param array<string, mixed> $metadata @param array<string, mixed> $geography */
    private function reason(CommissionAllocation $allocation, array $metadata, ?string $level, array $geography): string
    {
        if ($allocation->category === self::BenefitsCategory) {
            return 'Purchase surplus after company MRP share and the approved commission schedule.';
        }
        if ($allocation->status === 'unassigned_reserve') {
            $place = trim(implode(' / ', array_filter([
                $geography['division'] ?? null,
                $geography['district'] ?? null,
                $geography['upazila'] ?? null,
                $geography['union'] ?? null,
            ], fn ($value): bool => trim((string) $value) !== '')));

            return 'No active '.($level ?: 'area').' dealer assigned'.($place !== '' ? ' for '.$place : '').'; retained in company geographic reserve.';
        }
        if ($allocation->category === 'area_management') {
            return 'Management share of the area dealer pool retained by the company.';
        }
        if (str_contains((string) $allocation->category, 'breakage')) {
            return 'Missing referral recipient; amount retained as breakage reserve.';
        }
        if (str_contains((string) $allocation->category, 'override_reserve')) {
            return 'Individual rate reduction retained as company override reserve.';
        }
        if ($allocation->category === 'company_marketing_fund') {
            return 'Marketing reserve funded from the purchase-value commission schedule.';
        }
        if ($allocation->category === 'car_bike_incentive_fund') {
            return 'Vehicle reward reserve funded from the purchase-value commission schedule.';
        }

        return (string) ($metadata['source'] ?? 'Company retained commission amount.');
    }

    private function fundLabel(CommissionAllocation $allocation): string
    {
        return match (true) {
            $allocation->category === self::BenefitsCategory => 'Company Commission Benefits Fund',
            $allocation->status === 'unassigned_reserve' => 'Unassigned geographic reserve',
            $allocation->category === 'area_management' => 'Management area share',
            $allocation->category === 'company_marketing_fund' => 'Marketing fund',
            $allocation->category === 'car_bike_incentive_fund' => 'Car / motorcycle fund',
            str_contains((string) $allocation->category, 'override_reserve') => 'Override reserve',
            str_contains((string) $allocation->category, 'breakage') => 'Referral breakage reserve',
            default => (string) $allocation->category,
        };
    }

    /** @param list<array<string, mixed>>|Collection<int, array<string, mixed>> $rows */
    private function sumAmount(array|Collection $rows, string $key = 'amount'): float
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += Money::cents((string) ($row[$key] ?? '0'));
        }

        return round($total / 100, 2);
    }
}
