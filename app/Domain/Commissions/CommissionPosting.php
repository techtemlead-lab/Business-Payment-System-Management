<?php

namespace App\Domain\Commissions;

use App\Domain\Finance\Percentage;
use App\Domain\Geography\ManagementReferral;
use App\Domain\Geography\PlaceGeographyResolver;
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
    /** Marks a share that belongs to the company profit fund from the moment the order posts. */
    public const CompanyProfit = 'company_profit_fund';

    public function __construct(
        private CommissionPolicy $policies,
        private CommissionWallet $wallets,
        private CommissionQualification $qualification,
        private PlaceGeographyResolver $geographyResolver,
        private ManagementReferral $managementReferral,
    ) {}

    /** @return array<string, mixed> */
    public function capture(Order $order, User $buyer, string $event = 'submission', array $replacements = [], ?User $actor = null): array
    {
        if (($order->commission_policy_snapshot['capture_event'] ?? null) === 'approval') {
            return $order->commission_policy_snapshot;
        }
        $snapshot = $this->policies->snapshot(true, $buyer);
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
                throw ValidationException::withMessages(['items' => __('portal.err_post_price_funding')]);
            }
        }
        $existing = $order->commission_policy_snapshot;
        $snapshot['capture_event'] = $event;
        $snapshot['geography'] = $this->geographyResolver->forBuyer($buyer);
        $referral = $this->managementReferral->settings();
        $snapshot['management_referral'] = [
            'fund_from_generation' => $this->managementReferral->fundFromGeneration($buyer),
            'place_code' => $referral['place_code'],
            'place_code_id' => $referral['place_code_id'],
            'fund' => ManagementReferral::FundRetainedBy,
        ];
        $snapshot['uplines'] = [];
        $seen = [$buyer->id];
        $next = $buyer->sponsor_id;
        while ($next && count($snapshot['uplines']) < 100) {
            if (in_array($next, $seen, true)) {
                throw ValidationException::withMessages(['sponsor_id' => __('portal.err_post_circular_referral')]);
            }
            $seen[] = $next;
            $upline = User::findOrFail($next);
            $snapshot['uplines'][] = $upline->id;
            $next = $upline->sponsor_id;
        }
        if ($event === 'approval' && $existing) {
            $snapshot['geography'] = $existing['geography'];
            $snapshot['uplines'] = $existing['uplines'];
            if (array_key_exists('management_referral', $existing)) {
                $snapshot['management_referral'] = $existing['management_referral'];
            } else {
                unset($snapshot['management_referral']);
            }
        }
        $snapshot['referral_replacements'] = $this->replacements($snapshot['uplines'], $buyer, $replacements, $actor, $event);
        $geography = $snapshot['geography'];
        $placeIds = array_filter([
            $geography['place_ids']['division'] ?? null,
            $geography['place_ids']['district'] ?? null,
            $geography['place_ids']['upazila'] ?? null,
            $geography['place_ids']['union'] ?? null,
        ]);
        // Area seats are matched by place code only; a buyer without one has no area dealers.
        $areaUsers = $placeIds === [] ? [] : AreaDealerAssignment::where('is_active', true)
            ->whereIn('place_code_id', $placeIds)
            ->pluck('user_id')
            ->all();
        $snapshot['recipient_overrides'] = $this->policies->recipientOverrides([$buyer->id, ...$snapshot['uplines'], ...array_column($snapshot['referral_replacements'], 'user_id'), ...$areaUsers]);
        if ($event === 'approval') {
            $preview = $this->calculate($order, $buyer, $snapshot);
            $snapshot['approved_by'] = $actor?->id;
            $snapshot['funding_decisions'] = $preview['funding_decisions'];
            $snapshot['approved_company_share'] = Money::decimal($preview['company']);
        }
        $order->update(['commission_policy_snapshot' => $snapshot]);

        return $snapshot;
    }

    /** @return list<array<string, mixed>> */
    public function process(Order $order, ?int $actorId = null): array
    {
        if (
            $order->is_opening_balance
            || config('madina.preview_mode')
            || ! config('madina.financial_activation')
            || Money::cents($order->total) === 0
        ) {
            return [];
        }
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($order, $actorId): array {
            CommissionWallet::lock();
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->is_opening_balance) {
                return [];
            }
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
            ['rows' => $rows, 'mrp' => $mrp, 'company' => $company, 'allocated' => $allocated, 'days' => $days, 'funding_decisions' => $decisions] = $this->calculate($order, $buyer, $snapshot);
            $snapshot['funding_decisions'] = $decisions;
            $rules = $snapshot['rules'];
            $type = $snapshot['account_type'];
            $unallocated = $cash - $company - $allocated;
            DB::table('commission_batches')->insert(['order_id' => $order->id, 'account_type' => $type, 'period_month' => CommissionQualification::currentMonth(),
                'cash_basis' => Money::decimal($cash), 'mrp_basis' => Money::decimal($mrp), 'company_share' => Money::decimal($company),
                'total_allocated' => Money::decimal($allocated), 'unallocated' => Money::decimal($unallocated),
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
            if ($unallocated > 0) {
                $rateBps = intdiv(($unallocated * 10000) + intdiv($cash, 2), max(1, $cash));
                CommissionAllocation::create([
                    'order_id' => $order->id,
                    'user_id' => null,
                    'category' => 'company_commission_benefits_fund',
                    'rate_percentage' => number_format($rateBps / 100, 2, '.', ''),
                    'cash_basis' => $order->total,
                    'amount' => Money::decimal($unallocated),
                    'original_amount' => Money::decimal($unallocated),
                    'status' => 'reserved',
                    'metadata' => [
                        'basis' => 'purchase_value',
                        'period_month' => CommissionQualification::currentMonth(),
                        'retained_by' => 'owner_commission_benefits_fund',
                        'owner_only' => true,
                        'account_type' => $type,
                        'source' => 'purchase_surplus_after_company_share_and_commissions',
                    ],
                ]);
            }
            $credited = array_sum(array_column($journal, 'credit'));
            if ($credited > 0) {
                $journal[] = ['user_id' => null, 'account' => 'commission_expense', 'debit' => $credited, 'credit' => 0];
                $locale = $buyer->preferredLocale();
                $this->wallets->journal(
                    'commissions.order.'.$order->id,
                    __('portal.jr_commissions_earned', ['number' => $order->number], $locale),
                    $journal,
                );
            }
            $this->qualification->record($buyer, $cash, $rules);
            Audit::record($actorId, 'commissions.calculated', $order->id, [
                'buyer_id' => $buyer->id,
                'account_type' => $type,
                'cash_basis' => $order->total,
                'allocated' => Money::decimal($allocated),
                'benefits_fund' => Money::decimal($unallocated),
            ]);

            return CommissionAllocation::where('order_id', $order->id)->get()->toArray();
        }, 3);
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function calculate(Order $order, User $buyer, array $snapshot): array
    {
        $cash = Money::cents($order->total);
        $rules = $snapshot['rules'];
        $this->policies->validateSchedule($rules);
        $mrp = 0;
        $lineCash = 0;
        foreach ($order->lines as $line) {
            $mrp += Money::cents($line->mrp) * $line->quantity;
            $lineCash += Money::cents($line->total);
        }
        if ($mrp <= 0 || $lineCash !== $cash) {
            throw ValidationException::withMessages(['commissions' => __('portal.err_post_reconcile')]);
        }
        $company = Percentage::from($snapshot['company_mrp_percent'])->of($mrp, 'half_up');
        $type = $snapshot['account_type'];
        $rows = [];
        $add = function (string $category, string $ruleKey, ?int $userId = null, string $status = 'pending', ?string $rate = null, ?int $amount = null, array $metadata = []) use (&$rows, $rules, $cash, $snapshot): void {
            $percentage = $rate === null ? CommissionPolicy::rate($rules, $ruleKey) : Percentage::from($rate);
            $value = $amount ?? $percentage->of($cash, 'half_up');
            if ($percentage->basisPoints > 0 || $userId && isset($snapshot['recipient_overrides'][$userId]['rates'][$category])) {
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
        $uplines = array_slice($uplines, 0, 3);
        foreach ($snapshot['referral_replacements'] ?? [] as $generation => $replacement) {
            $uplines[(int) $generation - 1] = $replacement['user_id'];
        }
        $fundFrom = $snapshot['management_referral']['fund_from_generation'] ?? null;
        $managementMeta = [
            'management_fund' => true,
            'retained_by' => ManagementReferral::FundRetainedBy,
            'place_code' => $snapshot['management_referral']['place_code'] ?? null,
            'place_code_id' => $snapshot['management_referral']['place_code_id'] ?? null,
            'account_type' => $type,
        ];
        $toManagementFund = fn (int $generation): bool => is_int($fundFrom) && $generation >= $fundFrom;
        $directId = $uplines[0] ?? null;
        if ($directId) {
            $add('direct_referral', 'direct_referral', $directId, $recipient($directId));
        } elseif ($toManagementFund(1)) {
            $add('direct_referral', 'direct_referral', null, 'pending', null, null, [
                ...$managementMeta,
                'generation' => 1,
            ]);
        } else {
            $add('direct_referral_breakage', 'direct_referral', null, 'unassigned_reserve', null, null, [
                'unassigned' => true,
                // Owner rule: money with nobody to receive it is company profit straight away.
                'retained_by' => self::CompanyProfit,
                'reason_key' => 'retained_reason_profit_no_upline',
                'source_category' => 'direct_referral',
                'account_type' => $type,
                'generation' => 1,
            ]);
        }
        foreach ([2, 3] as $depth) {
            $rate = CommissionPolicy::rate($rules, 'd2d_multitier')->basisPoints > 0 ? (string) $rules['d2d_multitier']['parameters']['gen'.$depth.'_pct'] : '0.00';
            $id = $uplines[$depth - 1] ?? null;
            if ($id) {
                $add('d2d_gen'.$depth, 'd2d_multitier', $id, $recipient($id), $rate);
            } elseif ($toManagementFund($depth)) {
                $add('d2d_gen'.$depth, 'd2d_multitier', null, 'pending', $rate, null, [
                    ...$managementMeta,
                    'generation' => $depth,
                ]);
            } else {
                $add('d2d_gen'.$depth.'_breakage', 'd2d_multitier', null, 'unassigned_reserve', $rate, null, [
                    'unassigned' => true,
                    'retained_by' => self::CompanyProfit,
                    'reason_key' => 'retained_reason_profit_no_upline',
                    'source_category' => 'd2d_gen'.$depth,
                    'account_type' => $type,
                    'generation' => $depth,
                ]);
            }
        }
        $areaPool = CommissionPolicy::rate($rules, 'area_dealer_pool')->of($cash, 'half_up');
        $areaRemaining = $areaPool;
        $geography = $snapshot['geography'];
        foreach (['division', 'district', 'upazila', 'union'] as $level) {
            $rate = $areaPool > 0 ? (string) $rules['area_dealer_pool']['parameters'][$level.'_share_pct'] : '0.00';
            $share = min($areaRemaining, Percentage::from($rate)->of($cash, 'half_up'));
            $areaRemaining -= $share;
            $placeId = $geography['place_ids'][$level] ?? null;
            $placeCode = $geography['codes'][$level] ?? null;
            // Only a seat holding this exact place code earns the share. No place code
            // (or no seat) sends it to the company's geographic reserve below.
            $assignments = $placeId
                ? AreaDealerAssignment::where('level', $level)
                    ->where('is_active', true)
                    ->where('place_code_id', $placeId)
                    ->get()
                : collect();
            $assignment = $assignments->count() === 1 ? $assignments->first() : null;
            $id = $assignment?->user_id;
            $status = $recipient($id);
            $quotaHold = false;
            if ($assignment && $this->qualification->monthlyVolume($id, CommissionQualification::currentMonth()) < Money::cents($assignment->monthly_quota)) {
                $status = 'held';
                $quotaHold = true;
            }
            $add('area_'.$level, 'area_dealer_pool', $id, $id ? $status : 'unassigned_reserve', $rate, $share,
                [
                    'assignment_id' => $assignment?->id,
                    'monthly_quota' => $assignment?->monthly_quota,
                    'unassigned' => ! $id,
                    // No dealer holds this place code at this level (yet): the share is company
                    // profit at order time. A dealer seated later earns only on later orders.
                    'retained_by' => $id ? null : self::CompanyProfit,
                    'reason_key' => $id ? null : 'retained_reason_profit_no_area_seat',
                    'source_category' => 'area_'.$level,
                    'level' => $level,
                    'hold_reason' => $quotaHold ? 'quota' : null,
                    'account_type' => $type,
                    'place_code' => $placeCode,
                    'place_code_id' => $placeId,
                ]);
        }
        $add('area_management', 'area_dealer_pool', null, 'pending', (string) $rules['area_dealer_pool']['parameters']['management_share_pct'], $areaRemaining,
            ['account_type' => $type, 'retained_by' => ManagementReferral::FundRetainedBy, 'management_fund' => true]);
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
        $funding = app(RecipientFunding::class)->apply($rows, $snapshot, $cash, $company);
        $rows = $funding['rows'];
        $company = $funding['company'];
        $allocated = array_sum(array_column($rows, 'cents'));
        if ($company + $allocated > $cash) {
            throw ValidationException::withMessages(['commissions' => __('portal.err_post_product_funding')]);
        }

        return ['rows' => $rows, 'cash' => $cash, 'mrp' => $mrp, 'company' => $company, 'allocated' => $allocated, 'days' => $days, 'funding_decisions' => $funding['decisions']];
    }

    /** @param list<int> $uplines @param array<int, int> $requested @return array<int, array<string, int|string>> */
    private function replacements(array $uplines, User $buyer, array $requested, ?User $actor, string $event): array
    {
        if ($requested === []) {
            return [];
        }
        abort_unless($event === 'approval' && $actor?->fresh()->canAct('finance', 'edit'), 403);
        $selected = array_slice($uplines, 0, 3);
        $result = [];
        foreach ($requested as $generation => $userId) {
            if (! in_array((int) $generation, [1, 2, 3], true)) {
                throw ValidationException::withMessages(['replacement' => __('portal.err_post_replacement_slot')]);
            }
            $original = isset($uplines[$generation - 1]) ? User::find($uplines[$generation - 1]) : null;
            if (! $original || $original->status === 'active' && in_array($original->role, ['dealer', 'sub_dealer'], true)) {
                throw ValidationException::withMessages(['replacement' => __('portal.err_post_replacement_inactive')]);
            }
            $replacement = User::whereKey($userId)->where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->first();
            if (! $replacement || $replacement->id === $buyer->id) {
                throw ValidationException::withMessages(['replacement' => __('portal.err_post_replacement_partner')]);
            }
            $selected[$generation - 1] = $replacement->id;
            $result[$generation] = ['original_user_id' => $original->id, 'user_id' => $replacement->id, 'authorized_by' => $actor->id, 'authorized_at' => now()->toIso8601String()];
        }
        if (count(array_unique($selected)) !== count($selected)) {
            throw ValidationException::withMessages(['replacement' => __('portal.err_post_replacement_dup')]);
        }

        return $result;
    }
}
