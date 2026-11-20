<?php

namespace App\Domain\Finance;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who a company-fund spend was for. A spend for "the company itself" has no rows, exactly as before. A spend for
 * partners gets one row each (the partner and their share), written in the same transaction as the spend.
 *
 * These rows are only a record of what the company spent on a partner (a tour, an event prize, a contest prize).
 * They never touch the wallet, the pending balance, the journal or any fund balance.
 */
final class FundSpendPartners
{
    public const Company = 'company';

    public const Selected = 'partners';

    public const AllDealers = 'all_dealers';

    public const AllSubDealers = 'all_sub_dealers';

    public const Everyone = 'everyone';

    /** @var list<string> */
    public const Modes = [self::Company, self::Selected, self::AllDealers, self::AllSubDealers, self::Everyone];

    /** The most partners one spend can name by hand; "everyone" and "all of a type" have no such limit. */
    public const MaxSelected = 200;

    /**
     * The partners a spend is for, by id (lowest first). Empty for the company itself.
     *
     * @param  array{mode?: ?string, partner_ids?: array<int, mixed>}|null  $for
     * @return list<int>
     */
    public function resolve(?array $for): array
    {
        $mode = (string) ($for['mode'] ?? self::Company);
        if (! in_array($mode, self::Modes, true)) {
            throw ValidationException::withMessages(['for.mode' => __('funds.err_for_mode')]);
        }
        if ($mode === self::Company) {
            return [];
        }
        $partners = DB::table('users')->where('status', 'active');
        if ($mode === self::Selected) {
            $ids = array_values(array_unique(array_map('intval', (array) ($for['partner_ids'] ?? []))));
            if ($ids === [] || count($ids) > self::MaxSelected) {
                throw ValidationException::withMessages(['for.partner_ids' => __('funds.err_for_pick', ['max' => self::MaxSelected])]);
            }
            $found = (clone $partners)->whereIn('role', ['dealer', 'sub_dealer'])->whereIn('id', $ids)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if (count($found) !== count($ids)) {
                throw ValidationException::withMessages(['for.partner_ids' => __('funds.err_for_partner')]);
            }

            return $found;
        }
        $roles = match ($mode) {
            self::AllDealers => ['dealer'],
            self::AllSubDealers => ['sub_dealer'],
            default => ['dealer', 'sub_dealer'],
        };
        $found = $partners->whereIn('role', $roles)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($found === []) {
            throw ValidationException::withMessages(['for.mode' => __('funds.err_for_none')]);
        }

        return $found;
    }

    /**
     * The amount split equally; the odd paisa go one each to the first partners, so the shares add up to the amount exactly.
     *
     * @param  list<int>  $ids
     * @return array<int, string> partner id => share
     */
    public function split(string $amount, array $ids): array
    {
        $total = Money::cents($amount);
        $count = count($ids);
        $base = intdiv($total, $count);
        $extra = $total - $base * $count;
        $shares = [];
        foreach ($ids as $index => $id) {
            $shares[$id] = Money::decimal($base + ($index < $extra ? 1 : 0));
        }

        return $shares;
    }

    /**
     * Writes the rows for a spend. Call it inside the spend's own transaction.
     *
     * @param  list<int>  $ids
     */
    public function record(int $disbursementId, string $amount, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $rows = [];
        foreach ($this->split($amount, $ids) as $id => $share) {
            $rows[] = ['disbursement_id' => $disbursementId, 'user_id' => $id, 'share_amount' => $share, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('fund_spend_partners')->insert($chunk);
        }
    }

    /**
     * What the company has spent on one partner, newest first: one line per approved spend that names them.
     * A spend that is still waiting, refused or withdrawn is not shown. It is money spent on them, not a wallet credit.
     *
     * @return array{total: string, count: int, lines: list<array{id: string, date: string, what: string, purpose: string, amount: string, status: string}>}
     */
    public function linesFor(User $partner, int $limit = 20): array
    {
        $base = fn () => DB::table('fund_spend_partners as p')
            ->join('reserve_disbursements as r', 'r.id', '=', 'p.disbursement_id')
            ->where('p.user_id', $partner->id)->where('r.program', FundSpending::Program)->where('r.status', 'paid');
        $sum = (clone $base())->selectRaw('COALESCE(SUM(p.share_amount), 0) AS total, COUNT(*) AS count')->first();
        $lines = $base()->leftJoin('fund_expense_heads as h', 'h.id', '=', 'r.expense_head_id')
            ->orderByRaw('COALESCE(r.paid_at, r.spent_on::timestamp, r.created_at) DESC')->orderByDesc('p.id')->limit($limit)
            ->get(['p.id', 'p.share_amount', 'r.fund_key', 'r.reason', 'r.paid_at', 'r.spent_on', 'r.created_at', 'h.name_en', 'h.name_bn']);
        $locale = app()->getLocale();

        return [
            'total' => Money::decimal(Money::cents((string) $sum->total)),
            'count' => (int) $sum->count,
            'lines' => $lines->map(function (object $row) use ($locale): array {
                $fund = (string) __('funds.fund_'.$row->fund_key);
                $fund = $fund === 'funds.fund_'.$row->fund_key ? FundCatalog::name((string) $row->fund_key) : $fund;
                $head = (string) ($locale === 'bn' ? $row->name_bn : $row->name_en);
                $date = $row->paid_at ?? ($row->spent_on !== null ? $row->spent_on.' 00:00:00' : $row->created_at);

                return [
                    'id' => 'b-'.$row->id,
                    'date' => CarbonImmutable::parse((string) $date, 'UTC')->toIso8601String(),
                    'what' => $head !== '' ? $fund.' · '.$head : $fund,
                    'purpose' => trim((string) $row->reason),
                    'amount' => Money::decimal(Money::cents((string) $row->share_amount)),
                    'status' => 'paid_by_office',
                ];
            })->all(),
        ];
    }
}
