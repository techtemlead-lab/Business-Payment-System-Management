<?php

namespace Tests\Unit;

use App\Domain\Commissions\FairPaisaSplit;
use PHPUnit\Framework\TestCase;

class FairPaisaSplitTest extends TestCase
{
    public function test_parts_always_add_up_to_the_pool(): void
    {
        $split = FairPaisaSplit::forMonth('2026-09');

        foreach ([0, 1, 2, 7, 100, 20000, 99999] as $total) {
            foreach ([[5], [3, 9], [4, 2, 8], [1, 2, 3, 4, 5, 6, 7]] as $ids) {
                $parts = $split->divide($total, $ids);
                $this->assertSame($total, array_sum($parts));
                $this->assertLessThanOrEqual(1, max($parts) - min($parts));
            }
        }
        $this->assertSame([], $split->divide(100, []));
    }

    public function test_extra_paisa_take_turns_within_a_month(): void
    {
        $split = FairPaisaSplit::forMonth('2026-09');
        $received = [10 => 0, 20 => 0, 30 => 0];

        // Three pools of 200 taka between three dealers leave 2 paisa over each time.
        for ($pool = 0; $pool < 3; $pool++) {
            foreach ($split->divide(20000, [10, 20, 30], 'salary') as $id => $paisa) {
                $received[$id] += $paisa;
            }
        }

        $this->assertSame([10 => 20000, 20 => 20000, 30 => 20000], $received);
    }

    public function test_the_first_extra_paisa_moves_on_each_month_and_repeats_for_the_same_month(): void
    {
        $september = FairPaisaSplit::forMonth('2026-09')->divide(10, [1, 2, 3]);
        $october = FairPaisaSplit::forMonth('2026-10')->divide(10, [1, 2, 3]);

        $this->assertNotSame(array_keys($september, 4), array_keys($october, 4));
        $this->assertSame($september, FairPaisaSplit::forMonth('2026-09')->divide(10, [3, 1, 2]));
    }

    public function test_separate_groups_keep_separate_turns(): void
    {
        $split = FairPaisaSplit::forMonth('2026-01');

        $first = $split->divide(1, [1, 2], 'royalty_mo');
        $other = $split->divide(1, [1, 2], 'royalty_am');

        $this->assertSame($first, $other);
    }
}
