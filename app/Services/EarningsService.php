<?php

namespace App\Services;

use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class EarningsService
{
    public const CATEGORIES = [
        'purchase_commission' => 'Purchase dealer commission',
        'referral_bonus' => 'Referral bonus · three generations',
        'area_dealer_commission' => 'Area dealer commission',
        'dealer_commission_allocation' => 'Dealer commission reward',
        'dealer_salary' => 'Dealer salary',
        'dealer_to_dealer' => 'Dealer-to-dealer reward',
        'rank_reward' => 'Rank reward',
        'rank_maintenance' => 'Rank-maintenance incentive',
        'vehicle_reward' => 'Car & motorcycle reward',
        'management_marketing' => 'Management & marketing',
        'other' => 'Other / uncategorized earnings',
    ];

    public function query(?int $dealerId, ?string $from, ?string $to): Builder
    {
        return LedgerEntry::query()
            ->leftJoin('ledger_entries as original', 'ledger_entries.reversal_of', '=', 'original.id')
            ->whereIn('ledger_entries.kind', ['earned', 'reversal'])
            ->when($dealerId, fn (Builder $query) => $query->where('ledger_entries.dealer_id', $dealerId))
            ->when($from, fn (Builder $query) => $query->where('ledger_entries.created_at', '>=', Carbon::parse($from, 'Asia/Dhaka')->startOfDay()->utc()))
            ->when($to, fn (Builder $query) => $query->where('ledger_entries.created_at', '<', Carbon::parse($to, 'Asia/Dhaka')->addDay()->startOfDay()->utc()));
    }

    public function categoryExpression(): string
    {
        return "COALESCE(ledger_entries.earning_category, original.earning_category, CASE WHEN EXISTS (SELECT 1 FROM purchases WHERE purchases.ledger_entry_id = ledger_entries.id OR purchases.ledger_entry_id = original.id) THEN 'purchase_commission' ELSE 'other' END)";
    }

    /** @return list<array{key: string, label: string, gross_minor: int, reversed_minor: int, net_minor: int, entries: int, status: string}> */
    public function breakdown(Builder $query, ?bool $isAreaDealer): array
    {
        $expression = $this->categoryExpression();
        $totals = (clone $query)->selectRaw($expression." AS category, SUM(CASE WHEN ledger_entries.kind = 'earned' THEN ledger_entries.amount_minor ELSE 0 END) AS gross, SUM(CASE WHEN ledger_entries.kind = 'reversal' THEN ledger_entries.amount_minor ELSE 0 END) AS reversals, COUNT(*) AS entries")
            ->groupByRaw($expression)->get()->keyBy('category');

        $rows = [];
        foreach (self::CATEGORIES as $key => $label) {
            $total = $totals->get($key);
            $gross = (int) ($total?->gross ?? 0);
            $reversals = (int) ($total?->reversals ?? 0);
            $rows[] = ['key' => $key, 'label' => $label, 'gross_minor' => $gross, 'reversed_minor' => -$reversals, 'net_minor' => $gross + $reversals, 'entries' => (int) ($total?->entries ?? 0), 'status' => $key === 'area_dealer_commission' && $isAreaDealer === false ? 'Not eligible for new awards' : ($total ? 'Recorded earnings' : ($key === 'purchase_commission' || $key === 'other' ? 'No earnings recorded' : 'Payout rules pending'))];
        }

        return $rows;
    }
}
