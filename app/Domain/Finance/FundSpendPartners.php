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

    public const Equal = 'equal';

    public const Amounts = 'amounts';

    public const Direct = 'direct';

    /** @var list<string> */
    public const Splits = [self::Equal, self::Amounts, self::Direct];

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
     * Who gets what from a spend. Nobody for the company itself; otherwise by the chosen split: equal (the default),
     * an amount typed for each chosen partner (they must add up to the spend), or "the company pays directly" (the partners
     * are listed with no amount of their own).
     *
     * @param  array{mode?: ?string, partner_ids?: array<int, mixed>, split?: ?string, amounts?: array<int|string, mixed>}|null  $for
     * @return array<int, ?string> partner id => share; null means the company pays directly and the partner has no amount of their own
     */
    public function plan(?array $for, string $amount): array
    {
        $ids = $this->resolve($for);
        if ($ids === []) {
            return [];
        }
        $split = (string) ($for['split'] ?? self::Equal);
        if (! in_array($split, self::Splits, true)) {
            throw ValidationException::withMessages(['for.split' => __('funds.err_for_split')]);
        }

        return match ($split) {
            self::Direct => array_fill_keys($ids, null),
            self::Amounts => $this->typed((array) ($for['amounts'] ?? []), $ids, (string) ($for['mode'] ?? ''), $amount),
            default => $this->split($amount, $ids),
        };
    }

    /**
     * The amounts typed for each chosen partner. Every chosen partner needs one, and together they must equal the spend exactly.
     *
     * @param  array<int|string, mixed>  $typed
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function typed(array $typed, array $ids, string $mode, string $amount): array
    {
        if ($mode !== self::Selected) {
            throw ValidationException::withMessages(['for.split' => __('funds.err_for_amounts_mode')]);
        }
        $shares = [];
        $sum = 0;
        foreach ($ids as $id) {
            $value = trim((string) ($typed[$id] ?? $typed[(string) $id] ?? ''));
            if (preg_match('/^\d{1,9}(\.\d{1,2})?$/D', $value) !== 1 || Money::cents($value) <= 0) {
                throw ValidationException::withMessages(['for.amounts' => __('funds.err_for_amount_each')]);
            }
            $shares[$id] = Money::decimal(Money::cents($value));
            $sum += Money::cents($value);
        }
        if ($sum !== Money::cents($amount)) {
            throw ValidationException::withMessages(['for.amounts' => __('funds.err_for_sum', ['entered' => Money::grouped(Money::decimal($sum)), 'spend' => Money::grouped(Money::decimal(Money::cents($amount)))])]);
        }

        return $shares;
    }

    /**
     * Writes the rows for a spend. Call it inside the spend's own transaction.
     *
     * @param  array<int, ?string>  $shares  partner id => share (null: the company pays directly)
     */
    public function record(int $disbursementId, array $shares): void
    {
        if ($shares === []) {
            return;
        }
        $rows = [];
        foreach ($shares as $id => $share) {
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
     * @return array{total: string, count: int, lines: list<array{id: string, date: string, what: string, purpose: string, amount: ?string, status: string}>}
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
                    // No amount when the company paid directly (a tour for many members): the line still shows.
                    'amount' => $row->share_amount === null ? null : Money::decimal(Money::cents((string) $row->share_amount)),
                    'status' => 'paid_by_office',
                ];
            })->all(),
        ];
    }
}
