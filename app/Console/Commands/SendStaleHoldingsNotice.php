<?php

namespace App\Console\Commands;

use App\Domain\Finance\StaleHoldings;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('finance:stale-holdings-notice')]
#[Description('Tell the owners, once a day, about money that has waited longer than the limit for someone to act (portal notice only, no SMS or email)')]
class SendStaleHoldingsNotice extends Command
{
    /** One notice a day per owner, kind by kind, with the amount and where to act. Reads only; moves no money. */
    public function handle(StaleHoldings $holdings): int
    {
        $today = now()->timezone((string) config('madina.business_timezone', 'Asia/Dhaka'))->toDateString();
        if (DB::table('audit_events')->where('action', 'finance.stale_holdings_notice')->where('subject', $today)->exists()) {
            $this->info('Today\'s notice was already sent.');

            return self::SUCCESS;
        }
        $summary = $holdings->summary();
        if ($summary === []) {
            $this->info('No money has waited longer than the limit. No notice sent.');

            return self::SUCCESS;
        }
        $days = $holdings->days();
        $body = function (string $locale) use ($summary, $days): string {
            $lines = [__('money_core.stale_notice_intro', ['days' => LocalDigits::text((string) $days, $locale)], $locale)];
            foreach ($summary as $kind) {
                $lines[] = '• '.__('money_core.stale_notice_line', [
                    'what' => __('money_core.stale_kind_'.$kind['kind'], [], $locale),
                    'count' => LocalDigits::text((string) $kind['count'], $locale),
                    'amount' => Money::display($kind['amount'], $locale),
                    'where' => __('money_core.stale_where_'.$kind['kind'], [], $locale),
                ], $locale);
            }
            $lines[] = __('money_core.stale_notice_open', [], $locale);

            return implode("\n", $lines);
        };
        Audit::notifyOwners('finance', fn (string $locale): string => (string) __('money_core.stale_notice_title', [], $locale), $body);
        Audit::record(null, 'finance.stale_holdings_notice', $today, ['days' => $days, 'summary' => $summary]);
        $this->info(count($summary).' kind(s) of old money reported to the owners.');

        return self::SUCCESS;
    }
}
