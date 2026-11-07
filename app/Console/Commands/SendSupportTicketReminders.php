<?php

namespace App\Console\Commands;

use App\Domain\Support\SupportTickets;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('support:overdue-reminders')]
#[Description('Tell staff who answer support tickets how many tickets have waited too long for an office reply (portal notice only, no SMS)')]
class SendSupportTicketReminders extends Command
{
    /**
     * Post one portal notice per eligible staff member. Reads tickets only; never changes one.
     */
    public function handle(SupportTickets $tickets): int
    {
        $overdue = $tickets->overdueCount();
        if ($overdue === 0) {
            $this->info('No overdue support tickets. No notices sent.');

            return self::SUCCESS;
        }
        $hours = SupportTickets::overdueHours();

        $recipients = User::query()
            ->where('status', 'active')
            ->where(function ($users): void {
                $users->whereIn('role', ['super_admin', 'md', 'chairman', 'employee', ...array_keys(config('madina.roles', []))])
                    ->orWhere('role', 'like', 'custom_%');
            })
            ->get()
            ->filter(fn (User $user): bool => $user->allows('accounts'));

        foreach ($recipients as $user) {
            $locale = $user->preferredLocale();
            Audit::notice(
                $user->id,
                __('portal.notice_support_overdue_title', [], $locale),
                trans_choice('portal.notice_support_overdue_body', $overdue, ['count' => $overdue, 'hours' => $hours], $locale),
            );
        }

        $this->info("{$overdue} overdue support tickets. Notices sent to {$recipients->count()} staff.");

        return self::SUCCESS;
    }
}
