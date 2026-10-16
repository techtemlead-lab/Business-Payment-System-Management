<?php

namespace App\Domain\Commissions;

use App\Domain\Finance\Percentage;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionPosting
{
    public function __construct(private CommissionPolicy $policies, private CommissionWallet $wallets, private CommissionQualification $qualification) {}

    /** @return array<string, mixed> */
    public function capture(Order $order, User $buyer): array
    {
        $snapshot = $this->policies->snapshot();
        $snapshot['account_type'] = $buyer->role;
        if (config('madina.financial_activation')) {
            $rate = 0;
            $keys = $buyer->role === 'sub_dealer' ? [...CommissionPolicy::Keys, 'additional_rank', 'sub_dealer_salary', 'sub_dealer_tour'] : CommissionPolicy::Keys;
            foreach ($keys as $key) {
                $rate += CommissionPolicy::rate($snapshot['rules'], $key)->basisPoints;
            }
            $mrp = $order->lines->sum(fn ($line): int => Money::cents($line->mrp) * $line->quantity);
            $cash = Money::cents($order->total);
            if ($mrp <= 0 || $cash * 10000 < $mrp * 3000 + $cash * $rate) {
                throw ValidationException::withMessages(['items' => 'Purchase prices do not fund the company share and commission schedule. Ask Finance to correct pricing or policy.']);
            }
        }
        $snapshot['geography'] = ['division' => $buyer->division, 'district' => $buyer->district,
            'upazila' => $buyer->profile['upazila'] ?? null, 'union' => $buyer->profile['union'] ?? null];
        $snapshot['uplines'] = [];
        $seen = [$buyer->id];
        $next = $buyer->sponsor_id;
        while ($next && count($snapshot['uplines']) < 100) {
            if (in_array($next, $seen, true)) {
                throw ValidationException::withMessages(['sponsor_id' => 'Circular referral chain must be repaired before ordering.']);
            }
            $seen[] = $next;
            $upline = User::findOrFail($next);
            $snapshot['uplines'][] = $upline->id;
            $next = $upline->sponsor_id;
        }
        $order->update(['commission_policy_snapshot' => $snapshot]);

        return $snapshot;
    }

    /** @return list<array<string, mixed>> */
    public function process(Order $order): array
    {
        if (! config('madina.financial_activation') || Money::cents($order->total) === 0) {
            return [];
        }

        return DB::transaction(function () use ($order): array {
            CommissionWallet::lock();
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (DB::table('commission_batches')->where('order_id', $order->id)->exists() || CommissionAllocation::where('order_id', $order->id)->exists()) {
                return CommissionAllocation::where('order_id', $order->id)->get()->toArray();
            }
            $cash = Money::cents($order->total);
            if ($order->status !== 'delivered' || $cash === 0 || Money::cents($order->paid) !== $cash) {
                return [];
            }
            $buyer = User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            if (! in_array($buyer->role, ['dealer', 'sub_dealer'], true)) {
                return [];
            }
            $snapshot = $order->commission_policy_snapshot ?? $this->capture($order, $buyer);
            $rules = $snapshot['rules'];
            $this->policies->validateSchedule($rules);
            $mrp = 0;
            $lineCash = 0;
            foreach ($order->lines as $line) {
                $mrp += Money::cents($line->mrp) * $line->quantity;
                $lineCash += Money::cents($line->total);
            }
            if ($mrp <= 0 || $lineCash !== $cash) {
                throw ValidationException::withMessages(['commissions' => 'Order lines and collected purchase value must reconcile before posting earnings.']);
            }
            $company = Percentage::from($snapshot['company_mrp_percent'])->of($mrp, 'half_up');
            $type = $snapshot['account_type'];
            $rows = [];
            $add = function (string $category, string $ruleKey, ?int $userId = null, string $status = 'pending', ?string $rate = null, ?int $amount = null, array $metadata = []) use (&$rows, $rules, $cash): void {
                $percentage = $rate === null ? CommissionPolicy::rate($rules, $ruleKey) : Percentage::from($rate);
                $value = $amount ?? $percentage->of($cash, 'half_up');
                if ($percentage->basisPoints > 0) {
                    $rows[] = ['category' => $category, 'rule_key' => $ruleKey, 'user_id' => $userId, 'status' => $status,
                        'rate_percentage' => $percentage->decimal(), 'cents' => $value, 'metadata' => $metadata];
                }
            };
            $days = ($rules['commission_cooling_off']['is_active'] ?? false) ? (int) $rules['commission_cooling_off']['parameters']['cooling_off_days'] : 0;
            $eligibleStatus = $days > 0 ? 'maturing' : 'available';
            $recipient = function (?int $id) use ($eligibleStatus): string {
                $user = $id ? User::find($id) : null;

                return $user && $user->status === 'active' && in_array($user->role, ['dealer', 'sub_dealer'], true) ? $eligibleStatus : ($id ? 'held' : 'pending');
            };
            $add('own_dealer_cashback', 'own_dealer_cashback', $buyer->id, $recipient($buyer->id));
            $uplines = $snapshot['uplines'];
            if ($rules['d2d_multitier']['parameters']['dynamic_compression'] ?? false) {
                $uplines = array_values(array_filter($uplines, fn (int $id): bool => User::whereKey($id)->where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->exists()));
            }
            $add(isset($uplines[0]) ? 'direct_referral' : 'direct_referral_breakage', 'direct_referral', $uplines[0] ?? null, $recipient($uplines[0] ?? null));
            foreach ([2, 3] as $depth) {
                $rate = CommissionPolicy::rate($rules, 'd2d_multitier')->basisPoints > 0 ? (string) $rules['d2d_multitier']['parameters']['gen'.$depth.'_pct'] : '0.00';
                $id = $uplines[$depth - 1] ?? null;
                $add('d2d_gen'.$depth.($id ? '' : '_breakage'), 'd2d_multitier', $id, $recipient($id), $rate);
            }
            $areaPool = CommissionPolicy::rate($rules, 'area_dealer_pool')->of($cash, 'half_up');
            $areaRemaining = $areaPool;
            $geography = $snapshot['geography'];
            $path = [];
            foreach (['division', 'district', 'upazila', 'union'] as $level) {
                $path[$level] = $geography[$level] ?? null;
                $rate = $areaPool > 0 ? (string) $rules['area_dealer_pool']['parameters'][$level.'_share_pct'] : '0.00';
                $share = min($areaRemaining, Percentage::from($rate)->of($cash, 'half_up'));
                $areaRemaining -= $share;
                $query = AreaDealerAssignment::where('level', $level)->where('is_active', true);
                foreach ($path as $field => $value) {
                    $query->whereRaw('LOWER(TRIM("'.$field.'")) = ?', [mb_strtolower(trim((string) $value))]);
                }
                $assignments = collect($path)->contains(fn ($value): bool => trim((string) $value) === '') ? collect() : $query->get();
                $assignment = $assignments->count() === 1 ? $assignments->first() : null;
                $id = $assignment?->user_id;
                $status = $recipient($id);
                if ($assignment && $this->qualification->monthlyVolume($id, CommissionQualification::currentMonth()) < Money::cents($assignment->monthly_quota)) {
                    $status = 'held';
                }
                $add('area_'.$level, 'area_dealer_pool', $id, $id ? $status : 'unassigned_reserve', $rate, $share,
                    ['assignment_id' => $assignment?->id, 'unassigned' => ! $id, 'retained_by' => $id ? null : 'company_geographic_reserve']);
            }
            $add('area_management', 'area_dealer_pool', null, 'pending', (string) $rules['area_dealer_pool']['parameters']['management_share_pct'], $areaRemaining);
            foreach (['dealer_monthly_salary' => 'dealer_monthly_salary_pool', 'rank_royalty' => 'rank_royalty_pool',
                'rank_incentive' => 'rank_incentive_pool', 'company_marketing' => 'company_marketing_fund', 'car_bike_incentive' => 'car_bike_incentive_fund'] as $key => $category) {
                $add($category, $key);
            }
            if ($type === 'sub_dealer') {
                $add('additional_rank_pool', 'additional_rank');
                $add('sub_dealer_salary', 'sub_dealer_salary', $buyer->id);
                $add('sub_dealer_tour', 'sub_dealer_tour', $buyer->id, 'reserved');
            }
            $totalRate = array_sum(array_map(fn (array $row): int => Percentage::from($row['rate_percentage'])->basisPoints, $rows));
            $target = intdiv($cash * $totalRate + 5000, 10000);
            if ($target + $company === $cash + 1 && $cash * 10000 >= $mrp * 3000 + $cash * $totalRate) {
                $target--;
            }
            $groups = [];
            foreach ($rows as $index => $row) {
                $groups[$row['rule_key']][] = $index;
            }
            $groupAmounts = [];
            $groupFractions = [];
            foreach ($groups as $key => $indexes) {
                $numerator = $cash * CommissionPolicy::rate($rules, $key)->basisPoints;
                $groupAmounts[$key] = intdiv($numerator, 10000);
                $groupFractions[$key] = $numerator % 10000;
            }
            arsort($groupFractions, SORT_NUMERIC);
            $remaining = $target - array_sum($groupAmounts);
            foreach (array_keys($groupFractions) as $key) {
                if ($remaining-- <= 0) {
                    break;
                }
                $groupAmounts[$key]++;
            }
            foreach ($groups as $key => $indexes) {
                $fractions = [];
                $base = 0;
                foreach ($indexes as $index) {
                    $numerator = $cash * Percentage::from($rows[$index]['rate_percentage'])->basisPoints;
                    $rows[$index]['cents'] = intdiv($numerator, 10000);
                    $base += $rows[$index]['cents'];
                    $fractions[$index] = $numerator % 10000;
                }
                arsort($fractions, SORT_NUMERIC);
                $remaining = $groupAmounts[$key] - $base;
                foreach (array_keys($fractions) as $index) {
                    if ($remaining-- <= 0) {
                        break;
                    }
                    $rows[$index]['cents']++;
                }
            }
            $allocated = array_sum(array_column($rows, 'cents'));
            if ($company + $allocated > $cash) {
                throw ValidationException::withMessages(['commissions' => 'This product price cannot fund the approved company share and commission schedule. Correct pricing or policy before posting.']);
            }
            DB::table('commission_batches')->insert(['order_id' => $order->id, 'account_type' => $type, 'period_month' => CommissionQualification::currentMonth(),
                'cash_basis' => Money::decimal($cash), 'mrp_basis' => Money::decimal($mrp), 'company_share' => Money::decimal($company),
                'total_allocated' => Money::decimal($allocated), 'unallocated' => Money::decimal($cash - $company - $allocated),
                'policy' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $journal = [];
            foreach ($rows as $row) {
                $metadata = [...$row['metadata'], 'rule_version' => $rules[$row['rule_key']]['version'], 'basis' => 'purchase_value',
                    'period_month' => CommissionQualification::currentMonth(), 'maturation_due_at' => $days > 0 ? now()->addDays($days)->toIso8601String() : null];
                CommissionAllocation::create(['order_id' => $order->id, 'user_id' => $row['user_id'], 'category' => $row['category'],
                    'rate_percentage' => $row['rate_percentage'], 'cash_basis' => $order->total, 'amount' => Money::decimal($row['cents']),
                    'original_amount' => Money::decimal($row['cents']), 'status' => $row['status'], 'metadata' => $metadata]);
                if (in_array($row['status'], ['available', 'maturing'], true) && $row['user_id']) {
                    $held = $row['status'] === 'maturing';
                    $journal[] = ['user_id' => $row['user_id'], 'account' => $held ? 'commission_held_liability' : 'wallet_liability', 'debit' => 0, 'credit' => $row['cents']];
                    $this->wallets->credit($row['user_id'], $row['cents'], $held);
                }
            }
            $credited = array_sum(array_column($journal, 'credit'));
            if ($credited > 0) {
                $journal[] = ['user_id' => null, 'account' => 'commission_expense', 'debit' => $credited, 'credit' => 0];
                $this->wallets->journal('commissions.order.'.$order->id, 'Earned commissions for '.$order->number, $journal);
            }
            $this->qualification->record($buyer, $cash, $rules);
            Audit::record($buyer->id, 'commissions.calculated', $order->id, ['cash_basis' => $order->total, 'allocated' => Money::decimal($allocated)]);

            return CommissionAllocation::where('order_id', $order->id)->get()->toArray();
        }, 3);
    }
}
