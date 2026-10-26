<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PartnerWalletStatement
{
    /** @var list<string> */
    private const Accounts = ['wallet_liability', 'commission_held_liability'];

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
                'description' => (string) ($row->description ?: $row->event_key),
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
