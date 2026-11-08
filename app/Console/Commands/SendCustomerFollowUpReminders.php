<?php

namespace App\Console\Commands;

use App\Domain\Accounts\CustomerBook;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('partners:customer-follow-up-reminders')]
#[Description('Tell each partner how many of their own customers are due for a follow-up (portal notice only, no SMS)')]
class SendCustomerFollowUpReminders extends Command
{
    /**
     * At most one notice per partner per day. It carries counts only, never a customer's name
     * or number, because a notice can also appear on a phone's lock screen.
     */
    public function handle(CustomerBook $book): int
    {
        $counts = $book->dueCountsByPartner();
        $today = CustomerBook::today();
        $partners = User::query()
            ->whereIn('id', array_keys($counts))
            ->where('status', 'active')
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->get();

        $sent = 0;
        foreach ($partners as $partner) {
            if (! Cache::add("partner-tools:customer-follow-up:{$today}:{$partner->id}", true, now()->addDays(2))) {
                continue;
            }
            $locale = $partner->preferredLocale();
            Audit::notice(
                (int) $partner->id,
                __('dealer_tools.customer_notice_title', [], $locale),
                __('dealer_tools.customer_notice_body', [
                    'today' => $counts[(int) $partner->id]['due_today'],
                    'overdue' => $counts[(int) $partner->id]['overdue'],
                ], $locale),
            );
            $sent++;
        }
        $this->info("Customer follow-up notices posted: {$sent}.");

        return self::SUCCESS;
    }
}
