<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A hash chain over the audit trail (SA-04).
 *
 * `seal()` gives every new row the next place in the chain: its hash covers the row's own values
 * and the previous row's hash, so changing, removing or reordering any sealed row breaks every
 * hash after it. Rows are sealed in the order they are found, one sealer at a time (an advisory
 * lock), and a row is sealed only once (the database refuses any other change).
 * `verify()` walks the chain and reports the first break. The latest hash is the anchor to copy
 * off the server each day: a whole rewritten chain cannot match an anchor kept elsewhere.
 */
final class AuditChain
{
    public const Genesis = '0000000000000000000000000000000000000000000000000000000000000000';

    private const LockKey = 7270418;

    /** @return int how many rows were sealed */
    public function seal(int $max = 5000): int
    {
        $sealed = 0;
        DB::transaction(function () use ($max, &$sealed): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [self::LockKey]);
            }
            $last = DB::table('audit_events')->whereNotNull('chain_seq')->orderByDesc('chain_seq')->first(['chain_seq', 'row_hash']);
            $seq = (int) ($last->chain_seq ?? 0);
            $previous = (string) ($last->row_hash ?? self::Genesis);
            $rows = DB::table('audit_events')->whereNull('row_hash')->orderBy('id')->limit($max)
                ->get(['id', 'actor_id', 'action', 'subject', 'metadata', 'created_at']);
            foreach ($rows as $row) {
                $seq++;
                $hash = $this->hash($previous, $seq, $row);
                DB::table('audit_events')->where('id', $row->id)->whereNull('row_hash')
                    ->update(['chain_seq' => $seq, 'prev_hash' => $previous, 'row_hash' => $hash]);
                $previous = $hash;
                $sealed++;
            }
        });

        return $sealed;
    }

    /**
     * @return array{ok: bool, checked: int, unsealed: int, broken_at: ?int, anchor: array{seq: int, hash: string}}
     */
    public function verify(): array
    {
        $previous = self::Genesis;
        $expectedSeq = 0;
        $checked = 0;
        $brokenAt = null;
        DB::table('audit_events')->whereNotNull('chain_seq')->orderBy('chain_seq')
            ->select(['id', 'actor_id', 'action', 'subject', 'metadata', 'created_at', 'chain_seq', 'prev_hash', 'row_hash'])
            ->lazyById(1000, 'chain_seq')
            ->each(function (object $row) use (&$previous, &$expectedSeq, &$checked, &$brokenAt): bool {
                $expectedSeq++;
                $checked++;
                if ((int) $row->chain_seq !== $expectedSeq
                    || ! hash_equals($previous, (string) $row->prev_hash)
                    || ! hash_equals($this->hash($previous, $expectedSeq, $row), (string) $row->row_hash)) {
                    $brokenAt = (int) $row->id;

                    return false;
                }
                $previous = (string) $row->row_hash;

                return true;
            });

        return [
            'ok' => $brokenAt === null,
            'checked' => $checked,
            'unsealed' => DB::table('audit_events')->whereNull('row_hash')->count(),
            'broken_at' => $brokenAt,
            'anchor' => ['seq' => $expectedSeq, 'hash' => $previous],
        ];
    }

    private function hash(string $previous, int $seq, object $row): string
    {
        return hash('sha256', implode("\x1F", [
            $previous,
            (string) $seq,
            (string) $row->id,
            (string) ($row->actor_id ?? ''),
            (string) $row->action,
            (string) $row->subject,
            (string) ($row->metadata ?? ''),
            (string) $row->created_at,
        ]));
    }
}
