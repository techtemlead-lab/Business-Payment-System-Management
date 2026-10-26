<?php

namespace App\Domain\Accounts;

use App\Models\CommissionAllocation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PartnerEarningsPresenter
{
    /**
     * @return array{
     *   available: string,
     *   pending_withdrawal: string,
     *   total_withdrawn: string,
     *   reserved_for_payout: string
     * }
     */
    public function withdrawalBalances(User $user): array
    {
        $wallet = DB::table('wallets')->where('user_id', $user->id)->first(['available', 'held', 'commission_held']);
        $available = Money::cents((string) ($wallet->available ?? '0'));
        $held = Money::cents((string) ($wallet->held ?? '0'));
        $commissionHeld = Money::cents((string) ($wallet->commission_held ?? '0'));
        $pending = Money::cents((string) DB::table('withdrawals')
            ->where('user_id', $user->id)
            ->whereIn('status', ['requested', 'approved', 'pending'])
            ->sum('amount'));
        $withdrawn = Money::cents((string) DB::table('withdrawals')
            ->where('user_id', $user->id)
            ->where('status', 'paid')
            ->sum(DB::raw('COALESCE(net_amount, amount)')));

        return [
            'available' => Money::decimal($available),
            'pending_withdrawal' => Money::decimal($pending),
            'total_withdrawn' => Money::decimal($withdrawn),
            'reserved_for_payout' => Money::decimal(max(0, $held - $commissionHeld)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ledgerRows(User $user, ?CarbonImmutable $fromUtc, ?CarbonImmutable $toUtc, int $limit = 50): array
    {
        /** @var Collection<int, CommissionAllocation> $rows */
        $rows = CommissionAllocation::query()
            ->with([
                'order:id,number,user_id,total',
                'order.user:id,name,dealer_code,role',
            ])
            ->where('user_id', $user->id)
            ->when($fromUtc, fn ($query) => $query->where('created_at', '>=', $fromUtc))
            ->when($toUtc, fn ($query) => $query->where('created_at', '<', $toUtc))
            ->latest()
            ->limit($limit)
            ->get();

        return $rows->map(function (CommissionAllocation $row): array {
            $buyer = $row->order?->user;
            $metadata = is_array($row->metadata) ? $row->metadata : [];
            $generation = $metadata['generation'] ?? null;

            return [
                'id' => $row->id,
                'created_at' => optional($row->created_at)?->toIso8601String(),
                'category' => $row->category,
                'rate_percentage' => (string) $row->rate_percentage,
                'amount' => (string) $row->amount,
                'status' => $row->status,
                'order_id' => $row->order_id,
                'order_number' => $row->order?->number,
                'source_user_id' => $buyer?->id,
                'source_name' => $buyer?->name,
                'source_code' => $buyer?->dealer_code,
                'source_role' => $buyer?->role,
                'generation' => $generation,
            ];
        })->all();
    }

    /**
     * @return array{from: ?string, to: ?string}
     */
    public function resolvePreset(?string $preset, ?string $from, ?string $to): array
    {
        $tz = (string) config('madina.business_timezone', config('app.timezone'));
        $now = CarbonImmutable::now($tz);

        return match ($preset) {
            'today' => ['from' => $now->toDateString(), 'to' => $now->toDateString()],
            'week' => ['from' => $now->startOfWeek()->toDateString(), 'to' => $now->toDateString()],
            'month' => ['from' => $now->startOfMonth()->toDateString(), 'to' => $now->toDateString()],
            'year' => ['from' => $now->startOfYear()->toDateString(), 'to' => $now->toDateString()],
            default => ['from' => $from, 'to' => $to],
        };
    }
}
