<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Tests about the sub-dealer rank fund. Since 2026-11-20 the sub-dealer extra part goes to the company fund
 * "Sub-dealer extra" by default; before that it went to the rank fund, which these tests are about. Call this after
 * the commission rules are seeded.
 */
trait SubDealerExtraInRankFund
{
    protected function sendSubDealerExtraToTheRankFund(): void
    {
        DB::table('commission_rule_parameters')->where('rule_key', 'additional_rank')->update(['parameters' => json_encode(['destination' => 'rank_fund'])]);
    }
}
