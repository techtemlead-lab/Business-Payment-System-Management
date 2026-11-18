<?php

namespace App\Domain\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Month-start running totals of the journal, so the trial balance does not sum every line since the
 * first day at each view. A snapshot is only made for a month start that is more than a day old, so an
 * entry whose transaction started before that moment has long since committed. Snapshots are built
 * oldest first and each one adds only the lines since the previous one.
 */
final class JournalOpeningSnapshots
{
    private const SettleHours = 24;

    /** Builds every missing month-start snapshot up to now; returns how many were made. */
    public function build(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $first = DB::table('journal_entries')->min('created_at');
        if ($first === null) {
            return 0;
        }
        $made = 0;
        $month = CarbonImmutable::parse((string) $first, 'UTC')->startOfMonth()->addMonth();
        while ($month <= $now->subHours(self::SettleHours)) {
            if (! DB::table('journal_opening_snapshots')->where('as_of', $month)->exists()) {
                $this->buildOne($month);
                $made++;
            }
            $month = $month->addMonth();
        }

        return $made;
    }

    /** @return array{as_of: CarbonImmutable, totals: array<string, array{debit: string, credit: string}>}|null the newest snapshot at or before the moment */
    public function latestBefore(CarbonImmutable $moment): ?array
    {
        $asOf = DB::table('journal_opening_snapshots')->where('as_of', '<=', $moment)->max('as_of');
        if ($asOf === null) {
            return null;
        }
        $totals = [];
        foreach (DB::table('journal_opening_snapshots')->where('as_of', $asOf)->get(['account', 'debit', 'credit']) as $row) {
            $totals[$row->account] = ['debit' => (string) $row->debit, 'credit' => (string) $row->credit];
        }

        return ['as_of' => CarbonImmutable::parse((string) $asOf, 'UTC'), 'totals' => $totals];
    }

    /** Previous snapshot plus the lines since it, summed by account in one statement. */
    private function buildOne(CarbonImmutable $month): void
    {
        $previous = DB::table('journal_opening_snapshots')->where('as_of', '<', $month)->max('as_of');
        DB::statement(
            'INSERT INTO journal_opening_snapshots (as_of, account, debit, credit, built_at) '
            .'SELECT ?, account, SUM(debit), SUM(credit), CURRENT_TIMESTAMP FROM ('
            .'SELECT account, debit, credit FROM journal_opening_snapshots WHERE as_of = ? '
            .'UNION ALL SELECT l.account, l.debit, l.credit FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id '
            .'WHERE j.created_at < ? AND j.created_at >= ?'
            .') parts GROUP BY account',
            [$month, $previous ?? '1900-01-01', $month, $previous ?? '1900-01-01'],
        );
    }
}
