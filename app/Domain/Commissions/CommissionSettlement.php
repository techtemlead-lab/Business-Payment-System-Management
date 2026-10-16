<?php

namespace App\Domain\Commissions;

use App\Domain\Finance\Percentage;
use App\Models\CommissionAllocation;
use App\Models\DealerRank;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionSettlement
{
    public function __construct(private CommissionWallet $wallets, private CommissionQualification $qualification, private CommissionPolicy $policies) {}

    /** @return array<string, mixed> */
    public function settle(string $month, string $kind): array
    {
        CommissionWallet::requireActive();
        [, $end] = CommissionQualification::period($month);
        if ($end->isFuture()) {
            throw ValidationException::withMessages(['period_month' => 'Close the calendar month before settling its salary or royalty.']);
        }

        return DB::transaction(function () use ($month, $kind): array {
            CommissionWallet::lock();
            $categories = $kind === 'salary' ? ['dealer_monthly_salary_pool', 'sub_dealer_salary'] : ['rank_royalty_pool'];
            $funds = CommissionAllocation::whereIn('category', $categories)->where('status', 'pending')
                ->where('metadata->period_month', $month)->orderBy('id')->lockForUpdate()->get();
            $total = $funds->sum(fn (CommissionAllocation $a): int => Money::cents($a->amount));
            if ($total === 0) {
                return ['status' => 'empty_pool', 'total_pool' => '0.00', 'qualifiers_count' => 0, 'total_distributed' => '0.00'];
            }
            $distributed = [];
            $tiers = [];
            foreach ($funds as $fund) {
                $source = DB::table('commission_batches')->where('order_id', $fund->order_id)->value('policy');
                $rules = $source ? json_decode($source, true, 512, JSON_THROW_ON_ERROR)['rules'] : $this->policies->snapshot()['rules'];
                $parameters = $rules['dealer_monthly_salary']['parameters'];
                $qualified = User::where('role', 'dealer')->where('status', 'active')->orderBy('id')->get()
                    ->filter(fn (User $user): bool => $this->qualification->monthlyVolume($user->id, $month) >= Money::cents((string) $parameters['min_monthly_volume']))->values();
                $cents = Money::cents($fund->amount);
                $shares = [];
                if ($fund->category === 'sub_dealer_salary') {
                    $partner = User::find($fund->user_id);
                    if ($partner?->role === 'sub_dealer' && $partner->status === 'active') {
                        $shares[$partner->id] = $cents;
                    }
                } elseif ($kind === 'salary') {
                    $shares = $this->divide($cents, $qualified->pluck('id')->all());
                } else {
                    $buckets = $this->rankBuckets($cents, $rules['rank_royalty']['parameters']);
                    foreach (['mo', 'am', 'sm', 'me'] as $index => $code) {
                        $ids = DealerRank::where('current_rank', '!=', 'dealer')->whereHas('user', fn ($q) => $q->where('role', 'dealer')->where('status', 'active'))
                            ->orderBy('user_id')->get()->filter(fn (DealerRank $rank): bool => $this->qualification->paidAs($rank->user_id, $month, $rules)['paid_as_rank'] === $code)
                            ->pluck('user_id')->all();
                        $tierShares = $this->divide($buckets[$index], $ids);
                        foreach ($tierShares as $id => $amount) {
                            $shares[$id] = ($shares[$id] ?? 0) + $amount;
                        }
                        $tiers[$code]['qualifiers'] = count($ids);
                        $tiers[$code]['allocated_cents'] = ($tiers[$code]['allocated_cents'] ?? 0) + array_sum($tierShares);
                        $tiers[$code]['reserve_cents'] = ($tiers[$code]['reserve_cents'] ?? 0) + $buckets[$index] - array_sum($tierShares);
                    }
                }
                foreach ($shares as $id => $amount) {
                    if ($amount > 0) {
                        $this->pay($fund, $id, $amount, $month, $kind);
                        $distributed[$id] = ($distributed[$id] ?? 0) + $amount;
                    }
                }
                $fund->update(['status' => 'settled', 'metadata' => [...$fund->metadata, 'settled_at' => now()->toIso8601String(),
                    'settled_period' => $month, 'distributed' => Money::decimal(array_sum($shares)), 'reserved' => Money::decimal($cents - array_sum($shares))]]);
            }
            $totalPaid = array_sum($distributed);
            $result = [];
            foreach ($distributed as $id => $amount) {
                $result[] = ['user_id' => $id, 'user_name' => User::find($id)?->name, 'amount' => Money::decimal($amount),
                    'monthly_volume' => Money::decimal($this->qualification->monthlyVolume($id, $month))];
            }
            foreach ($tiers as &$tier) {
                $tier['allocated'] = Money::decimal($tier['allocated_cents']);
                $tier['rolled_to_reserve'] = Money::decimal($tier['reserve_cents']);
                unset($tier['allocated_cents'], $tier['reserve_cents']);
            }
            Audit::record(null, 'commissions.'.$kind.'_pool_settled', $month, ['distributed' => Money::decimal($totalPaid)]);

            return ['status' => $totalPaid > 0 ? 'settled' : 'rolled_over', 'period' => $month, 'total_pool' => Money::decimal($total),
                'total_distributed' => Money::decimal($totalPaid), 'qualifiers_count' => count($distributed), 'distributed' => $result, 'distributions' => $tiers];
        }, 3);
    }

    /** @param list<int> $ids @return array<int, int> */
    private function divide(int $total, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $base = intdiv($total, count($ids));
        $remainder = $total % count($ids);
        $result = [];
        foreach ($ids as $index => $id) {
            $result[$id] = $base + ($index < $remainder ? 1 : 0);
        }

        return $result;
    }

    /** @param array<string,mixed> $parameters @return array<int,int> */
    private function rankBuckets(int $amount, array $parameters): array
    {
        $weights = [];
        foreach (['mo', 'am', 'sm', 'me'] as $key) {
            $weights[] = Percentage::from((string) $parameters[$key.'_share_pct'])->basisPoints;
        }
        $total = array_sum($weights);
        if ($total === 0) {
            throw ValidationException::withMessages(['rules' => 'The royalty pool needs nonzero rank shares.']);
        }
        $result = [];
        $fractions = [];
        foreach ($weights as $index => $weight) {
            $result[$index] = intdiv($amount * $weight, $total);
            $fractions[$index] = ($amount * $weight) % $total;
        }
        arsort($fractions, SORT_NUMERIC);
        $remainder = $amount - array_sum($result);
        foreach (array_keys($fractions) as $index) {
            if ($remainder-- <= 0) {
                break;
            } $result[$index]++;
        }

        return $result;
    }

    private function pay(CommissionAllocation $fund, int $userId, int $amount, string $month, string $kind): void
    {
        DB::table('commission_settlement_payouts')->insert(['allocation_id' => $fund->id, 'user_id' => $userId, 'kind' => $kind,
            'refunded_basis' => DB::table('commission_batches')->where('order_id', $fund->order_id)->value('refunded_amount') ?? '0.00',
            'period_month' => $month, 'amount' => Money::decimal($amount), 'created_at' => now(), 'updated_at' => now()]);
        $this->wallets->journal('commissions.settlement.'.$fund->id.'.'.$userId.'.'.$kind, 'Commission '.$kind.' settlement '.$month, [
            ['user_id' => null, 'account' => 'commission_expense', 'debit' => $amount, 'credit' => 0],
            ['user_id' => $userId, 'account' => 'wallet_liability', 'debit' => 0, 'credit' => $amount],
        ]);
        $this->wallets->credit($userId, $amount);
        Audit::notice($userId, 'Commission settlement', Money::decimal($amount).' BDT credited for '.$kind.' / '.$month.'.');
    }

    /** @return array<string, mixed> */
    public function incentive(int $userId): array
    {
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($userId): array {
            CommissionWallet::lock();
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $rank = DealerRank::where('user_id', $userId)->lockForUpdate()->firstOrFail();
            if ($user->role !== 'dealer' || $user->status !== 'active' || $rank->current_rank === 'dealer') {
                return ['success' => false, 'message' => 'An active dealer with an achieved leadership rank is required.'];
            }
            if (DB::table('rank_incentive_awards')->where('user_id', $userId)->where('rank', $rank->current_rank)->exists()) {
                return ['success' => false, 'message' => 'This rank incentive was already paid.'];
            }
            if ($rank->one_time_incentive_paid && ! DB::table('rank_incentive_awards')->where('user_id', $userId)->exists()) {
                return ['success' => false, 'message' => 'A legacy incentive payment is already recorded. Reconcile its rank history before another award.'];
            }
            $rules = $this->policies->snapshot()['rules'];
            $amount = Money::cents((string) $rules['rank_incentive']['parameters'][$rank->current_rank.'_bonus']);
            $funds = CommissionAllocation::where('category', 'rank_incentive_pool')->whereIn('status', ['pending', 'partially_settled'])->orderBy('id')->lockForUpdate()->get();
            $available = $funds->sum(fn ($fund): int => Money::cents($fund->amount) - Money::cents((string) ($fund->metadata['incentive_consumed'] ?? '0.00')));
            if ($amount <= 0 || $available < $amount) {
                return ['success' => false, 'message' => 'The rank incentive reserve does not yet fund this award.'];
            }
            $remaining = $amount;
            $sources = [];
            foreach ($funds as $fund) {
                $used = Money::cents((string) ($fund->metadata['incentive_consumed'] ?? '0.00'));
                $take = min($remaining, Money::cents($fund->amount) - $used);
                if ($take > 0) {
                    $this->pay($fund, $userId, $take, CommissionQualification::currentMonth(), 'rank_'.$rank->current_rank);
                    $sources[$fund->id] = Money::decimal($take);
                    $fund->update(['status' => $used + $take === Money::cents($fund->amount) ? 'settled' : 'partially_settled',
                        'metadata' => [...$fund->metadata, 'incentive_consumed' => Money::decimal($used + $take)]]);
                    $remaining -= $take;
                }
                if ($remaining === 0) {
                    break;
                }
            }
            DB::table('rank_incentive_awards')->insert(['user_id' => $userId, 'rank' => $rank->current_rank, 'amount' => Money::decimal($amount),
                'sources' => json_encode($sources, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $rank->update(['one_time_incentive_paid' => true, 'one_time_incentive_paid_at' => now()]);

            return ['success' => true, 'user_id' => $userId, 'rank' => $rank->current_rank, 'amount' => Money::decimal($amount), 'paid_at' => now()->toIso8601String()];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function release(): array
    {
        CommissionWallet::requireActive();

        return DB::transaction(function (): array {
            CommissionWallet::lock();
            $funds = CommissionAllocation::where('status', 'maturing')->whereNotNull('user_id')->orderBy('id')->lockForUpdate()->get();
            $count = 0;
            $total = 0;
            foreach ($funds as $fund) {
                $due = $fund->metadata['maturation_due_at'] ?? null;
                if (! $due || now()->lt(CarbonImmutable::parse($due)) || ! User::whereKey($fund->user_id)->where('status', 'active')->exists()) {
                    continue;
                }
                $amount = Money::cents($fund->amount);
                $result = $this->wallets->debit($fund->user_id, $amount, true);
                if ($result['unrecovered'] !== 0) {
                    throw ValidationException::withMessages(['commissions' => 'Commission holds do not reconcile.']);
                }
                $this->wallets->journal('commissions.matured.'.$fund->id, 'Release matured commission', [
                    ['user_id' => $fund->user_id, 'account' => 'commission_held_liability', 'debit' => $amount, 'credit' => 0],
                    ['user_id' => $fund->user_id, 'account' => 'wallet_liability', 'debit' => 0, 'credit' => $amount],
                ]);
                $this->wallets->credit($fund->user_id, $amount);
                $fund->update(['status' => 'available', 'metadata' => [...$fund->metadata, 'maturation_released' => true, 'matured_at' => now()->toIso8601String()]]);
                $count++;
                $total += $amount;
            }

            return ['status' => $count > 0 ? 'success' : 'no_matured_commissions', 'released_count' => $count, 'total_amount' => Money::decimal($total)];
        }, 3);
    }
}
