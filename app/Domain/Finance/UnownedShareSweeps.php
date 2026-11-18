<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Geography\ManagementReferral;
use App\Models\CommissionAllocation;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commission that has nobody to receive it, moved where the owner's rules say it belongs.
 *
 * - D1 (MA-04): a referral share with no upline is company profit. Rows the management fund
 *   collected under the old default are moved once.
 * - MA-05: an area share with no area dealer is already company profit at order time (owner rule),
 *   so nothing here moves it.
 * - D3 (MA-03): commission held for a suspended partner is paid when they are active again; for an
 *   account closed more than three months it goes to company profit.
 *
 * Every move writes a company profit row that points back to its source and marks the source
 * moved, so running any sweep again moves nothing twice.
 */
final class UnownedShareSweeps
{
    /** Months a closed account's held commission waits before it becomes company profit. */
    public const ClosedAccountMonths = 3;

    public const NoUplineReason = 'money_core.reason_no_upline';

    public const ClosedAccountReason = 'money_core.reason_closed_account';

    /** Referral levels that the management fund could have collected. */
    private const ReferralCategories = ['direct_referral', 'd2d_gen2', 'd2d_gen3'];

    public function __construct(private CommissionSettlement $settlement, private ManagementReferral $managementReferral) {}

    /**
     * One-time move of referral money with no upline from the management fund to company profit.
     * Skipped while the owner has ticked "send to the management fund" themselves. A row the
     * management fund has already spent from stays there, so the fund's history still adds up.
     *
     * @return array{status: string, moved_count: int, kept_count: int, total_amount: string}
     */
    public function moveNoUplineReferrals(?int $actorId = null): array
    {
        if ($this->managementReferral->settings()['route_referral_slices']) {
            return ['status' => 'owner_routes_to_management', 'moved_count' => 0, 'kept_count' => 0, 'total_amount' => '0.00'];
        }

        return DB::transaction(function () use ($actorId): array {
            CommissionWallet::lock();
            $rows = CommissionAllocation::query()
                ->whereNull('user_id')
                ->whereIn('category', self::ReferralCategories)
                ->where('status', 'pending')
                ->where('metadata->retained_by', ManagementReferral::FundRetainedBy)
                ->orderBy('id')->lockForUpdate()->get();
            $spent = $this->usedByRow($rows);
            $moved = 0;
            $kept = 0;
            $total = 0;
            foreach ($rows as $row) {
                if (($spent[$row->id] ?? 0) > 0) {
                    $kept++;

                    continue;
                }
                $total += $this->moveToCompanyProfit($row, Money::cents($row->amount), 'no_upline', self::NoUplineReason, ['moved_from' => 'management_fund']);
                $moved++;
            }
            if ($moved > 0 || $kept > 0) {
                Audit::record($actorId, 'commission.no_upline_moved_to_company_profit', 'management_fund',
                    ['moved' => $moved, 'kept_spent' => $kept, 'amount' => Money::decimal($total)]);
            }

            return ['status' => $moved > 0 ? 'moved' : 'nothing_to_move', 'moved_count' => $moved, 'kept_count' => $kept, 'total_amount' => Money::decimal($total)];
        }, 3);
    }

    /**
     * Pay commission that was held only because the partner was not active, now that they are (D3).
     * An area share held for a missed target keeps its own month-end rule.
     *
     * @return array{released_count: int, total_amount: string}
     */
    public function releaseHeldForActivePartners(?int $partnerId = null, ?int $actorId = null): array
    {
        if (! config('madina.financial_activation') || config('madina.preview_mode')) {
            return ['released_count' => 0, 'total_amount' => '0.00'];
        }
        $run = function () use ($partnerId, $actorId): array {
            CommissionWallet::lock();
            $rows = CommissionAllocation::query()
                ->where('status', 'held')
                ->whereNotNull('user_id')
                ->when($partnerId !== null, fn ($query) => $query->where('user_id', $partnerId))
                ->whereIn('user_id', User::query()->where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->select('id'))
                ->where(fn ($query) => $query->whereNull('metadata->hold_reason')->orWhere('metadata->hold_reason', '!=', 'quota'))
                ->orderBy('id')->lockForUpdate()->get();
            $reserves = app(ReserveService::class);
            $count = 0;
            $total = 0;
            foreach ($rows as $row) {
                try {
                    $total += $reserves->creditHeldAllocation($row, $actorId, (string) __('money_core.release_reason_reactivated'));
                    $count++;
                } catch (ValidationException) {
                    // An area share whose month target is not met yet stays held for the month-end rule.
                    continue;
                }
            }

            return ['released_count' => $count, 'total_amount' => Money::decimal($total)];
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run, 3);
    }

    /**
     * Held commission of an account closed more than three months ago goes to company profit (D3).
     *
     * @return array{status: string, moved_count: int, total_amount: string}
     */
    public function closedAccountHoldsToCompanyProfit(?int $actorId = null): array
    {
        $cutoff = now()->subMonthsNoOverflow(self::ClosedAccountMonths);

        return DB::transaction(function () use ($actorId, $cutoff): array {
            CommissionWallet::lock();
            $rows = CommissionAllocation::query()
                ->where('status', 'held')
                ->whereIn('user_id', User::query()->where('status', '!=', 'active')->whereNotNull('closed_at')->where('closed_at', '<=', $cutoff)->select('id'))
                ->orderBy('id')->lockForUpdate()->get();
            $total = 0;
            foreach ($rows as $row) {
                $total += $this->moveToCompanyProfit($row, Money::cents($row->amount), 'closed_account', self::ClosedAccountReason);
            }
            if ($rows->isNotEmpty()) {
                Audit::record($actorId, 'commission.closed_account_holds_to_company_profit', $cutoff->toDateString(),
                    ['count' => $rows->count(), 'amount' => Money::decimal($total)]);
            }

            return ['status' => $rows->isNotEmpty() ? 'moved' : 'nothing_to_move', 'moved_count' => $rows->count(), 'total_amount' => Money::decimal($total)];
        }, 3);
    }

    /**
     * Writes the company profit row and takes the source out of every fund, so the money is
     * counted once, in company profit, from now on.
     *
     * @param  array<string, mixed>  $extra
     */
    private function moveToCompanyProfit(CommissionAllocation $row, int $amount, string $kind, string $reasonKey, array $extra = []): int
    {
        $month = $this->monthOf($row);
        $profitId = $this->settlement->toCompanyProfit($row, $amount, $month, $kind, null, $reasonKey);
        $metadata = $row->metadata;
        $metadata['retained_by_before'] = $metadata['retained_by'] ?? null;
        unset($metadata['retained_by']);
        $row->update(['status' => 'forfeited', 'metadata' => [...$metadata, ...$extra, 'forfeited_at' => now()->toIso8601String(),
            'forfeited_to' => CommissionSettlement::CompanyProfitCategory, 'company_profit_allocation_id' => $profitId, 'forfeit_reason' => $kind]]);

        return $amount;
    }

    private function monthOf(CommissionAllocation $row): string
    {
        $month = $row->metadata['period_month'] ?? null;

        return is_string($month) && preg_match('/^\d{4}-\d{2}$/D', $month)
            ? $month
            : CarbonImmutable::parse($row->created_at)->timezone((string) config('madina.business_timezone'))->format('Y-m');
    }

    /**
     * Paisa already paid or spent from each row.
     *
     * @param  Collection<int, CommissionAllocation>  $rows
     * @return array<int, int>
     */
    private function usedByRow(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $funds = app(CompanyFunds::class);
        $used = [];
        foreach ([$funds->settlementPayouts(), $funds->expenseSources()] as $query) {
            foreach (DB::query()->fromSub($query, 'used')->whereIn('allocation_id', $rows->pluck('id'))->get(['allocation_id', 'total']) as $line) {
                $used[(int) $line->allocation_id] = ($used[(int) $line->allocation_id] ?? 0) + FundCatalog::centsOf($line->total);
            }
        }

        return $used;
    }
}
