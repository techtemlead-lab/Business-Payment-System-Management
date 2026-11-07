<?php

namespace App\Console\Commands;

use App\Domain\Orders\OverdueDeliveries;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:delivery-reminders')]
#[Description('Tell staff with the orders or inventory permission how many orders were sent over a week ago but are still not marked delivered (portal notice only, no SMS)')]
class SendDeliveryReminders extends Command
{
    /**
     * Post one portal notice per eligible staff member. Reads orders only; never changes an order or money.
     */
    public function handle(OverdueDeliveries $overdueDeliveries): int
    {
        $overdue = $overdueDeliveries->count();
        if ($overdue === 0) {
            $this->info('No overdue deliveries. No notices sent.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->where('status', 'active')
            ->where(function ($users): void {
                $users->whereIn('role', ['super_admin', 'md', 'chairman', 'employee', ...array_keys(config('madina.roles', []))])
                    ->orWhere('role', 'like', 'custom_%');
            })
            ->get()
            ->filter(fn (User $user): bool => OverdueDeliveries::visibleTo($user));

        foreach ($recipients as $user) {
            $locale = $user->preferredLocale();
            Audit::notice(
                $user->id,
                __('portal.notice_deliveries_overdue_title', [], $locale),
                trans_choice('portal.notice_deliveries_overdue_body', $overdue, ['count' => $overdue, 'days' => OverdueDeliveries::Days], $locale),
            );
        }

        $this->info("{$overdue} overdue deliveries. Notices sent to {$recipients->count()} staff.");

        return self::SUCCESS;
    }
}
