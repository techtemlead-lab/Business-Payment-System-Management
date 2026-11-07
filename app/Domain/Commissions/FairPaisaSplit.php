<?php

namespace App\Domain\Commissions;

/**
 * Splits money (in paisa) between people as evenly as possible.
 *
 * When a pool does not divide exactly, a few people get one extra paisa. Those extra
 * paisa are handed out in turn: the first turn depends on the month, and each later split
 * of the same group carries on from where the previous one stopped. So over a month every
 * qualifier gets about the same number of extra paisa, and the same dealers are not always
 * first. The parts always add up to the pool exactly, and the result is the same every time
 * for the same month and the same funds in the same order.
 */
final class FairPaisaSplit
{
    /** @var array<string, int> Where the next extra paisa goes, per group of people. */
    private array $nextTurn = [];

    public function __construct(private int $firstTurn) {}

    /** A splitter whose first extra paisa moves on by one person each business month. */
    public static function forMonth(string $month): self
    {
        [$year, $monthNumber] = array_map('intval', explode('-', $month, 2) + [1 => '1']);

        return new self($year * 12 + $monthNumber - 1);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> paisa per person, in id order
     */
    public function divide(int $total, array $ids, string $group = ''): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        sort($ids);
        $count = count($ids);
        $key = $group.'|'.implode(',', $ids);
        $start = ($this->nextTurn[$key] ?? $this->firstTurn) % $count;
        $base = intdiv($total, $count) - ($total % $count < 0 ? 1 : 0);
        $extra = $total - $base * $count;
        $result = [];
        foreach ($ids as $index => $id) {
            $turn = ($index - $start + $count) % $count;
            $result[$id] = $base + ($turn < $extra ? 1 : 0);
        }
        $this->nextTurn[$key] = ($start + $extra) % $count;

        return $result;
    }
}
