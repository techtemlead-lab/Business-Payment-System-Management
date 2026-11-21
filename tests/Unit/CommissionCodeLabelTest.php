<?php

namespace Tests\Unit;

use App\Support\CommissionCodeLabel;
use PHPUnit\Framework\TestCase;

/**
 * A commission code with no translation must never come back as an old name such as "D2d gen2" or "Direct referral".
 */
class CommissionCodeLabelTest extends TestCase
{
    public function test_retired_parts_are_written_with_the_current_names(): void
    {
        $this->assertSame('Team income 2nd step', CommissionCodeLabel::fromCode('d2d_gen2'));
        $this->assertSame('Team income 3rd step breakage', CommissionCodeLabel::fromCode('d2d_gen3_breakage'));
        $this->assertSame('Staff commission', CommissionCodeLabel::fromCode('direct_referral'));
        $this->assertSame('Team income', CommissionCodeLabel::fromCode('d2d_multitier'));
    }

    public function test_other_codes_keep_the_plain_headline_and_replace_retired_leaves_them_alone(): void
    {
        $this->assertSame('Rank Royalty Pool', CommissionCodeLabel::fromCode('rank_royalty_pool'));
        $this->assertSame('rank_royalty_pool', CommissionCodeLabel::replaceRetired('rank_royalty_pool'));
        $this->assertSame('team_income_2nd_step', CommissionCodeLabel::replaceRetired('d2d_gen2'));
    }
}
