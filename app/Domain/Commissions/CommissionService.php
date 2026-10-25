<?php

namespace App\Domain\Commissions;

use App\Domain\Finance\CalculationMessage;
use App\Domain\Finance\Percentage;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionService
{
    public function __construct(private CommissionPosting $posting, private CommissionSettlement $settlements,
        private CommissionReversal $reversals, private CommissionQualification $qualification,
        private CommissionPolicy $policies, private CommissionWallet $wallets) {}

    /** @return list<array<string, mixed>> */
    public function processOrder(Order $order, ?int $actorId = null): array
    {
        return $this->posting->process($order, $actorId);
    }

    /** @return list<array<string, mixed>> */
    public function reverseOrder(Order $order, ?string $reason = null, ?int $actorId = null, ?string $requestKey = null): array
    {
        $reason ??= __('portal.jr_order_cancelled_refunded');

        return $this->reversals->refund($order, null, $reason, 'full-refund', $actorId, $requestKey);
    }

    /** @return list<array<string, mixed>> */
    public function reverseOrderPartial(Order $order, string|float $refundAmount, ?string $reason = null, ?string $reference = null, ?int $actorId = null, ?string $requestKey = null): array
    {
        $reason ??= __('portal.jr_partial_refund');
        $amount = is_float($refundAmount) ? number_format($refundAmount, 2, '.', '') : $refundAmount;

        return $this->reversals->refund($order, Money::cents($amount), $reason, $reference ?? hash('sha256', $reason.'|'.$amount), $actorId, $requestKey);
    }

    /** @return array<string, mixed> */
    public function settleMonthlySalaryPool(string $periodMonth, ?int $actorId = null, ?string $requestKey = null): array
    {
        return $this->settlements->settle($periodMonth, 'salary', $actorId, $requestKey);
    }

    /** @return array<string, mixed> */
    public function settleRankRoyaltyPool(string $periodMonth, ?int $actorId = null, ?string $requestKey = null): array
    {
        return $this->settlements->settle($periodMonth, 'royalty', $actorId, $requestKey);
    }

    /** @return array<string, mixed> */
    public function claimOneTimeRankIncentive(int $userId, float $customAmount = 0, ?int $actorId = null, ?string $requestKey = null): array
    {
        if ($customAmount !== 0.0) {
            $rank = DealerRank::where('user_id', $userId)->value('current_rank');
            $approved = $this->policies->snapshot()['rules']['rank_incentive']['parameters'][$rank.'_bonus'] ?? '0.00';
            if (Money::cents(number_format($customAmount, 2, '.', '')) !== Money::cents((string) $approved)) {
                throw ValidationException::withMessages(['incentive' => __('portal.err_incentive_override')]);
            }
        }

        return $this->settlements->incentive($userId, $actorId, $requestKey);
    }

    public function creditWallet(int $userId, float $amount): void
    {
        CommissionWallet::requireActive();
        DB::transaction(fn () => $this->wallets->credit($userId, Money::cents(number_format($amount, 2, '.', ''))), 3);
    }

    public function holdWallet(int $userId, float $amount): void
    {
        CommissionWallet::requireActive();
        DB::transaction(fn () => $this->wallets->credit($userId, Money::cents(number_format($amount, 2, '.', '')), true), 3);
    }

    /** @return array<string, mixed> */
    public function releaseMaturedCommissions(?int $actorId = null, ?string $requestKey = null): array
    {
        return $this->settlements->release($actorId, $requestKey);
    }

    /** @return array<string, mixed> */
    public function evaluatePaidAsRank(int $userId): array
    {
        return $this->qualification->paidAs($userId);
    }

    /** @return array<string, mixed> */
    public function recalculateCarBikeForUser(int $userId): array
    {
        return $this->qualification->refreshMilestones($userId);
    }

    /** @return array<string, mixed> */
    public function evaluateAntiWhaleLegs(int $userId, string $type): array
    {
        return $this->qualification->milestone($userId, $type);
    }

    public function updateCarBikeMilestonesForAncestors(int $buyerId, float $cashAmount): void
    {
        foreach (ReferralTreePath::getUplines($buyerId, 100) as $path) {
            $this->qualification->refreshMilestones($path->ancestor_id);
        }
    }

    /** Preview only: no wallet, allocation, policy or rank writes. @return array<string, mixed> */
    public function calculatePayoutTds(float $amount, ?float $customTdsPct = null): array
    {
        $rules = $this->policies->snapshot()['rules'];
        $gross = Money::cents(number_format($amount, 2, '.', ''));
        $rate = $customTdsPct === null ? CommissionPolicy::rate($rules, 'payout_tax_withholding') : Percentage::from(number_format($customTdsPct, 2, '.', ''));
        $threshold = Money::cents((string) $rules['payout_tax_withholding']['parameters']['threshold']);
        $tax = $gross >= $threshold ? $rate->of($gross, 'half_up') : 0;

        return ['gross_amount' => Money::decimal($gross), 'tds_pct' => $rate->decimal(), 'tds_amount' => Money::decimal($tax), 'net_amount' => Money::decimal($gross - $tax), 'posts_earnings' => false];
    }

    /** @param array<string, mixed> $parameterOverrides @return array<string, mixed> */
    public function simulatePlan(array $parameterOverrides, ?string $dateFrom = null, ?string $dateTo = null, int $limitOrders = 100): array
    {
        $rules = $this->policies->snapshot()['rules'];
        $rates = [];
        foreach (CommissionPolicy::Keys as $key) {
            if ($key !== 'd2d_multitier') {
                $rates[$key] = CommissionPolicy::rate($rules, $key)->decimal();
            }
        }
        $rates['d2d_gen2'] = $rules['d2d_multitier']['is_active'] ? (string) $rules['d2d_multitier']['parameters']['gen2_pct'] : '0.00';
        $rates['d2d_gen3'] = $rules['d2d_multitier']['is_active'] ? (string) $rules['d2d_multitier']['parameters']['gen3_pct'] : '0.00';
        $subKeys = ['additional_rank', 'sub_dealer_salary', 'sub_dealer_tour'];
        foreach ($subKeys as $key) {
            $rates[$key] = CommissionPolicy::rate($rules, $key)->decimal();
        }
        if (array_diff_key($parameterOverrides, $rates) !== []) {
            throw ValidationException::withMessages(['overrides' => __('portal.err_sim_unknown_category')]);
        }
        $proposed = $rates;
        try {
            foreach ($parameterOverrides as $key => $value) {
                $proposed[$key] = Percentage::from((string) $value)->decimal();
            }
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['overrides' => CalculationMessage::localize($exception->getMessage())]);
        }
        $tz = (string) config('madina.business_timezone', config('app.timezone'));
        $orders = Order::with(['lines', 'user'])->where('status', 'delivered')->whereColumn('paid', 'total')
            ->when($dateFrom, function ($q) use ($dateFrom, $tz) {
                $start = CarbonImmutable::parse($dateFrom, $tz)->startOfDay()->utc();
                $q->where('created_at', '>=', $start);
            })
            ->when($dateTo, function ($q) use ($dateTo, $tz) {
                $end = CarbonImmutable::parse($dateTo, $tz)->addDay()->startOfDay()->utc();
                $q->where('created_at', '<', $end);
            })->orderBy('id')->limit(max(1, min($limitOrders, 1000)))->get();
        $refunds = DB::table('commission_batches')->whereIn('order_id', $orders->modelKeys())->pluck('refunded_amount', 'order_id');
        $cash = 0;
        $subCash = 0;
        foreach ($orders as $order) {
            $net = Money::cents($order->total) - Money::cents((string) ($refunds[$order->id] ?? '0.00'));
            $cash += $net;
            if (($order->commission_policy_snapshot['account_type'] ?? $order->user->role) === 'sub_dealer') {
                $subCash += $net;
            }
        }
        $synthetic = $orders->isEmpty();
        if ($synthetic) {
            $cash = 1000000;
        }
        $baselineTotal = 0;
        $proposedTotal = 0;
        $slices = [];
        $sum = 0;
        foreach ($rates as $key => $rate) {
            $categoryCash = in_array($key, $subKeys, true) ? $subCash : $cash;
            $base = Percentage::from($rate)->of($categoryCash, 'half_up');
            $test = Percentage::from($proposed[$key])->of($categoryCash, 'half_up');
            if (! in_array($key, $subKeys, true)) {
                $sum += Percentage::from($proposed[$key])->basisPoints;
            }
            $baselineTotal += $base;
            $proposedTotal += $test;
            $slices[] = ['key' => $key, 'name' => ucwords(str_replace('_', ' ', $key)), 'baseline_pct' => (float) $rate,
                'simulated_pct' => (float) $proposed[$key], 'baseline_amount' => $base / 100, 'simulated_amount' => $test / 100, 'delta_amount' => ($test - $base) / 100];
        }
        $subExtras = 0;
        foreach (['additional_rank', 'sub_dealer_salary', 'sub_dealer_tour'] as $key) {
            $subExtras += Percentage::from($proposed[$key])->basisPoints;
        }
        $safe = $sum <= 4000 && $sum + $subExtras <= 5000;
        foreach ($orders as $order) {
            if (Money::cents($order->total) === Money::cents((string) ($refunds[$order->id] ?? '0.00'))) {
                continue;
            }
            $mrp = $order->lines->sum(fn ($line): int => Money::cents($line->mrp) * $line->quantity);
            $cashCents = Money::cents($order->total);
            $basisPoints = $sum + (($order->commission_policy_snapshot['account_type'] ?? $order->user->role) === 'sub_dealer' ? $subExtras : 0);
            if ($mrp <= 0 || $cashCents * 10000 < $mrp * 3000 + $cashCents * $basisPoints) {
                $safe = false;
            }
        }
        $baseline = ['total_commissions' => $baselineTotal / 100, 'company_retention' => max(0, $cash - $baselineTotal) / 100, 'retention_pct' => $cash > 0 ? ($cash - $baselineTotal) / $cash * 100 : 0];
        $simulated = ['total_commissions' => $proposedTotal / 100, 'company_retention' => max(0, $cash - $proposedTotal) / 100, 'retention_pct' => $cash > 0 ? ($cash - $proposedTotal) / $cash * 100 : 0];

        return ['posts_earnings' => false, 'is_synthetic' => $synthetic, 'is_synthetic_benchmark' => $synthetic,
            'orders_evaluated_count' => $synthetic ? 1 : $orders->count(), 'orders_analyzed_count' => $synthetic ? 1 : $orders->count(),
            'total_gross_sales' => $cash / 100, 'gross_sales_tested' => $cash / 100, 'proposed_pool_sum_pct' => $sum / 100,
            'account_mix' => ['dealer_purchase_value' => Money::decimal($cash - $subCash), 'sub_dealer_purchase_value' => Money::decimal($subCash)],
            'total_proposed_pct' => $sum / 100, 'sub_dealer_pool_sum_pct' => ($sum + $subExtras) / 100, 'exceeds_cap' => ! $safe, 'is_safe' => $safe,
            'baseline' => $baseline, 'simulated' => $simulated, 'variance' => ['amount' => ($proposedTotal - $baselineTotal) / 100,
                'pct' => $baselineTotal > 0 ? ($proposedTotal - $baselineTotal) / $baselineTotal * 100 : 0],
            'slices' => $slices, 'slices_comparison' => $slices, 'guardrail_alerts' => [$safe ? __('portal.jr_preview_fits_limits') : __('portal.jr_preview_exceeds_limits')]];
    }
}
