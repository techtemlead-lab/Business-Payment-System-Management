<?php

namespace Tests\Unit;

use App\Domain\Commissions\EqualSplit;
use PHPUnit\Framework\TestCase;

/**
 * Owner's rule (2026-11-21): equal to the paisa, and the paisa that do not divide are left over for the Commission
 * extra fund, never given to one of the people.
 */
class EqualSplitTest extends TestCase
{
    public function test_one_hundred_taka_among_three(): void
    {
        $split = EqualSplit::among(10_000, [7, 3, 5]);

        $this->assertSame([3 => 3_333, 5 => 3_333, 7 => 3_333], $split['shares']);
        $this->assertSame(1, $split['leftover']);
        $this->assertSame(10_000, array_sum($split['shares']) + $split['leftover']);
    }

    public function test_one_paisa_among_seven_is_all_leftover(): void
    {
        $this->assertSame(['shares' => [], 'leftover' => 1], EqualSplit::among(1, [1, 2, 3, 4, 5, 6, 7]));
    }

    public function test_an_exact_split_leaves_nothing_and_nobody_leaves_everything(): void
    {
        $this->assertSame(['shares' => [1 => 50, 2 => 50], 'leftover' => 0], EqualSplit::among(100, [1, 2, 2]));
        $this->assertSame(['shares' => [], 'leftover' => 100], EqualSplit::among(100, []));
    }

    public function test_the_four_rank_funds_round_down_and_leave_the_paisa(): void
    {
        $this->assertSame(['parts' => [250, 250, 250, 250], 'leftover' => 1], EqualSplit::byWeights(1_001, [100, 100, 100, 100]));
        $this->assertSame(['parts' => [666, 333], 'leftover' => 1], EqualSplit::byWeights(1_000, [200, 100]));
    }
}
