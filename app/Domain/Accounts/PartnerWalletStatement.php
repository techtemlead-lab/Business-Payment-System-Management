<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PartnerWalletStatement
{
    /** @var list<string> */
    private const Accounts = ['wallet_liability', 'commission_held_liability'];

    /** Journal key of the commissions credited for one order (order ids are UUIDs). */
    private const OrderEvent = '/^commissions\.order\.([0-9a-f-]{36})$/i';

    /** The sentence each money event was written from, by the start of its journal key. */
    private const Sentences = [
        'commissions.order.' => 'jr_commissions_earned',
        'commissions.reversal.' => 'jr_commission_reversal',
        'commissions.settlement.' => 'jr_commission_settlement',
        'commissions.matured.' => 'jr_commission_matured',
        'commissions.debt_recovery.' => 'jr_clawback_offset',
        'commission.hold_release.' => 'jr_hold_release',
        'opening.hold_release.' => 'jr_opening_hold_release',
        'opening.import.' => 'ob_journal_approved',
        'reserve.award.' => 'jr_reserve_award',
        'manual-payout:' => 'wd_journal_manual_payout',
    ];

    /**
     * Build a partner wallet statement from liability journal lines.
     * Credits increase what the company owes the partner; debits reduce it.
     * Running balances are computed chronologically, then lines are returned newest-first.
     *
     * @return array{
     *   opening_balance: string,
     *   closing_balance: string,
     *   period_net: string,
     *   filters: array{from: ?string, to: ?string},
     *   lines: list<array{
     *     id: int,
     *     entry_id: string,
     *     occurred_at: string,
     *     description: string,
     *     event_key: string,
     *     account: string,
     *     debit: string,
     *     credit: string,
     *     balance: string
     *   }>
     * }
     */
    public function for(User $user, ?string $from = null, ?string $to = null, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        [$fromUtc, $toUtc] = $this->bounds($from, $to);

        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->where('l.user_id', $user->id)
            ->whereIn('l.account', self::Accounts)
            ->when($fromUtc, fn ($query) => $query->where('j.created_at', '>=', $fromUtc))
            ->when($toUtc, fn ($query) => $query->where('j.created_at', '<', $toUtc))
            ->orderByDesc('j.created_at')
            ->orderByDesc('l.id')
            ->limit($limit)
            ->get([
                'l.id',
                'l.entry_id',
                'l.account',
                'l.debit',
                'l.credit',
                'j.description',
                'j.event_key',
                'j.created_at',
            ]);

        $chronological = $rows->reverse()->values();
        $openingCents = $this->openingBalanceCents($user->id, $fromUtc, $chronological->first());

        $running = $openingCents;
        $lines = [];
        foreach ($chronological as $row) {
            $debitCents = Money::cents((string) $row->debit);
            $creditCents = Money::cents((string) $row->credit);
            $running += $creditCents - $debitCents;
            $lines[] = [
                'id' => (int) $row->id,
                'entry_id' => (string) $row->entry_id,
                'occurred_at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
                'description' => $this->wording($row),
                'event_key' => (string) $row->event_key,
                'account' => (string) $row->account,
                'debit' => Money::decimal($debitCents),
                'credit' => Money::decimal($creditCents),
                'balance' => $this->signedDecimal($running),
            ];
        }

        $closingCents = $running;
        $periodNet = $closingCents - $openingCents;

        return [
            'opening_balance' => $this->signedDecimal($openingCents),
            'closing_balance' => $this->signedDecimal($closingCents),
            'period_net' => $this->signedDecimal($periodNet),
            'filters' => ['from' => $from, 'to' => $to],
            'lines' => array_reverse($lines),
        ];
    }

    /**
     * The party ledger (পার্টির হিসাব) for one partner and date range: the
     * opening balance, one line per money movement oldest first with its
     * running balance, totals for the whole range and the closing balance.
     * Lines are paged on the server; there is no row cap. A movement between
     * held and available money inside one journal entry does not change the
     * balance, so it is left out.
     *
     * @return array{
     *   opening_balance: string,
     *   closing_balance: string,
     *   total_in: string,
     *   total_out: string,
     *   line_count: int,
     *   lines: LengthAwarePaginator<int, array{entry_id: string, occurred_at: string, description: string, reference: ?string, order_id: ?string, withdrawal_id: ?string, money_in: string, money_out: string, balance: string}>
     * }
     */
    public function ledger(User $user, string $from, string $to, int $perPage = 25): array
    {
        [$fromUtc, $toUtc] = $this->bounds($from, $to);
        $opening = $this->openingBalanceCents($user->id, $fromUtc, null);
        $totals = DB::query()->fromSub($this->ledgerEntries($user->id, $fromUtc, $toUtc), 'g')
            ->selectRaw('COUNT(*) AS line_count')
            ->selectRaw('COALESCE(SUM(GREATEST(g.net, 0)), 0) AS money_in')
            ->selectRaw('COALESCE(SUM(GREATEST(-g.net, 0)), 0) AS money_out')
            ->first();
        $in = $this->signedCents((string) ($totals->money_in ?? '0'));
        $out = $this->signedCents((string) ($totals->money_out ?? '0'));
        $page = $this->ledgerLines($user->id, $fromUtc, $toUtc)->paginate($perPage)->withQueryString();
        $page->setCollection(collect($this->ledgerRows($page->getCollection()->all(), $opening)));

        return [
            'opening_balance' => $this->signedDecimal($opening),
            'closing_balance' => $this->signedDecimal($opening + $in - $out),
            'total_in' => $this->signedDecimal($in),
            'total_out' => $this->signedDecimal($out),
            'line_count' => (int) ($totals->line_count ?? 0),
            'lines' => $page,
        ];
    }

    /**
     * Every ledger line in the range, oldest first, for the Excel and PDF downloads.
     *
     * @return Generator<int, array{entry_id: string, occurred_at: string, description: string, reference: ?string, order_id: ?string, withdrawal_id: ?string, money_in: string, money_out: string, balance: string}>
     */
    public function ledgerLinesFor(User $user, string $from, string $to): Generator
    {
        [$fromUtc, $toUtc] = $this->bounds($from, $to);
        $opening = $this->openingBalanceCents($user->id, $fromUtc, null);
        foreach ($this->ledgerLines($user->id, $fromUtc, $toUtc)->lazy(500)->chunk(500) as $chunk) {
            yield from $this->ledgerRows($chunk->all(), $opening);
        }
    }

    /**
     * Journal entries that moved this partner's money, one row each with the net change.
     */
    private function ledgerEntries(int $userId, ?CarbonImmutable $fromUtc, ?CarbonImmutable $toUtc): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->where('l.user_id', $userId)
            ->whereIn('l.account', self::Accounts)
            ->when($fromUtc, fn ($query) => $query->where('j.created_at', '>=', $fromUtc))
            ->when($toUtc, fn ($query) => $query->where('j.created_at', '<', $toUtc))
            ->groupBy('j.id', 'j.event_key', 'j.description', 'j.created_at')
            ->havingRaw('SUM(l.credit) <> SUM(l.debit)')
            ->select(['j.id as entry_id', 'j.event_key', 'j.description', 'j.created_at'])
            ->selectRaw('SUM(l.credit) - SUM(l.debit) AS net');
    }

    /**
     * Ledger entries oldest first with the change since the start of the range.
     */
    private function ledgerLines(int $userId, ?CarbonImmutable $fromUtc, ?CarbonImmutable $toUtc): Builder
    {
        return DB::query()->fromSub($this->ledgerEntries($userId, $fromUtc, $toUtc), 'g')
            ->select('g.*')
            ->selectRaw('SUM(g.net) OVER (ORDER BY g.created_at, g.entry_id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running')
            ->orderBy('g.created_at')
            ->orderBy('g.entry_id');
    }

    /**
     * Turns raw ledger entries into display lines, looking up order numbers in one query per batch.
     *
     * @param  list<object>  $entries
     * @return list<array{entry_id: string, occurred_at: string, description: string, reference: ?string, order_id: ?string, withdrawal_id: ?string, money_in: string, money_out: string, balance: string}>
     */
    private function ledgerRows(array $entries, int $openingCents): array
    {
        $orderIds = [];
        $refundIds = [];
        foreach ($entries as $entry) {
            if (preg_match(self::OrderEvent, (string) $entry->event_key, $match)) {
                $orderIds[] = $match[1];
            } elseif (preg_match('/^commissions\.reversal\.([0-9a-f-]{36})$/i', (string) $entry->event_key, $match)) {
                $refundIds[] = $match[1];
            }
        }
        $orders = $orderIds === [] ? collect() : DB::table('orders')->whereIn('id', array_unique($orderIds))->pluck('number', 'id');
        $refunds = $refundIds === [] ? collect() : DB::table('commission_refunds as f')->join('orders as o', 'o.id', '=', 'f.order_id')
            ->whereIn('f.id', array_unique($refundIds))->get(['f.id', 'o.id as order_id', 'o.number'])->keyBy('id');

        return array_map(function (object $entry) use ($orders, $refunds, $openingCents): array {
            $net = $this->signedCents((string) $entry->net);
            $reference = null;
            $orderId = null;
            $withdrawalId = null;
            if (preg_match(self::OrderEvent, (string) $entry->event_key, $match)) {
                $orderId = $match[1];
                $reference = $orders->get($orderId);
            } elseif (preg_match('/^commissions\.reversal\.([0-9a-f-]{36})$/i', (string) $entry->event_key, $match) && $refunds->has($match[1])) {
                $orderId = (string) $refunds->get($match[1])->order_id;
                $reference = (string) $refunds->get($match[1])->number;
            } elseif (preg_match('/^manual-payout:([0-9a-f-]{36})$/i', (string) $entry->event_key, $match)) {
                $withdrawalId = $match[1];
                $reference = 'WD-'.strtoupper(substr($match[1], 0, 8));
            }

            return [
                'entry_id' => (string) $entry->entry_id,
                'occurred_at' => CarbonImmutable::parse((string) $entry->created_at, 'UTC')->toIso8601String(),
                'description' => $this->wording($entry),
                'reference' => $reference,
                'order_id' => $orderId,
                'withdrawal_id' => $withdrawalId,
                'money_in' => Money::decimal(max($net, 0)),
                'money_out' => Money::decimal(max(-$net, 0)),
                'balance' => $this->signedDecimal($openingCents + $this->signedCents((string) $entry->running)),
            ];
        }, array_values($entries));
    }

    /**
     * The words of a ledger line in the reader's language. A journal row keeps the sentence
     * in the language it was written in, so a known event is worded again from its key.
     * Typed words (a refund reason, an import reference) stay as typed, and a line that
     * matches no known sentence shows its stored words.
     *
     * @param  object{event_key: string, description: ?string}  $entry
     */
    private function wording(object $entry): string
    {
        $eventKey = (string) $entry->event_key;
        $stored = (string) ($entry->description ?: $eventKey);
        foreach (self::Sentences as $start => $sentence) {
            if (str_starts_with($eventKey, $start)) {
                $values = $this->sentenceValues($sentence, $stored);

                return $values === null ? $stored : (string) __('portal.'.$sentence, $this->valuesInReaderLanguage($eventKey, $values));
            }
        }

        return $stored;
    }

    /**
     * The changing parts of a stored sentence, found by matching it to that sentence in each language.
     *
     * @return array<string, string>|null Null when the words match neither language
     */
    private function sentenceValues(string $sentence, string $stored): ?array
    {
        foreach (['bn', 'en'] as $locale) {
            $pattern = preg_replace('/\\\\:([a-z]+)/', '(?<$1>.+)', preg_quote((string) __('portal.'.$sentence, [], $locale), '/'));
            if (preg_match('/^'.$pattern.'$/u', $stored, $matches) === 1) {
                return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            }
        }

        return null;
    }

    /**
     * Swaps a settlement kind or reward program for its name in the reader's language.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function valuesInReaderLanguage(string $eventKey, array $values): array
    {
        if (isset($values['kind']) && preg_match('/^commissions\.settlement\.(?:([a-z_]+)\.\d{4}-\d{2}\.|.+\.([a-z_]+)$)/', $eventKey, $match) === 1) {
            $values['kind'] = $this->labelOr('portal.settle_kind_'.($match[1] ?: $match[2]), $values['kind']);
        }
        if (isset($values['program'])) {
            $program = DB::table('reserve_disbursements')->where('id', substr($eventKey, strlen('reserve.award.')))->value('program');
            $values['program'] = $program !== null ? $this->labelOr('portal.reward_program_'.$program, $values['program']) : $values['program'];
        }

        return $values;
    }

    private function labelOr(string $key, string $fallback): string
    {
        $label = (string) __($key);

        return $label === $key ? $fallback : $label;
    }

    /**
     * @param  object{id: int|string, created_at: mixed}|null  $oldestInPage
     */
    private function openingBalanceCents(int $userId, ?CarbonImmutable $fromUtc, ?object $oldestInPage): int
    {
        $query = DB::table('journal_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->where('l.user_id', $userId)
            ->whereIn('l.account', self::Accounts);

        if ($fromUtc !== null) {
            $query->where('j.created_at', '<', $fromUtc);
        } elseif ($oldestInPage !== null) {
            $query->where(function ($inner) use ($oldestInPage): void {
                $inner->where('j.created_at', '<', $oldestInPage->created_at)
                    ->orWhere(function ($sameMoment) use ($oldestInPage): void {
                        $sameMoment->where('j.created_at', '=', $oldestInPage->created_at)
                            ->where('l.id', '<', $oldestInPage->id);
                    });
            });
        } else {
            return 0;
        }

        $raw = (string) ($query->selectRaw('COALESCE(SUM(l.credit - l.debit), 0) as balance')->value('balance') ?? '0');

        return $this->signedCents($raw);
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function bounds(?string $from, ?string $to): array
    {
        $tz = (string) config('madina.business_timezone', 'Asia/Dhaka');
        $fromUtc = null;
        $toUtc = null;
        if (is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $fromUtc = CarbonImmutable::createFromFormat('Y-m-d', $from, $tz)->startOfDay()->utc();
        }
        if (is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $toUtc = CarbonImmutable::createFromFormat('Y-m-d', $to, $tz)->addDay()->startOfDay()->utc();
        }

        return [$fromUtc, $toUtc];
    }

    private function signedCents(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return 0;
        }
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '+-');
        if ($absolute === '') {
            return 0;
        }

        return ($negative ? -1 : 1) * Money::cents($absolute);
    }

    private function signedDecimal(int $cents): string
    {
        if ($cents < 0) {
            return '-'.Money::decimal(abs($cents));
        }

        return Money::decimal($cents);
    }
}
