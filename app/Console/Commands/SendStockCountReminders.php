<?php

namespace App\Console\Commands;

use App\Domain\Inventory\StockCountService;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stock:count-reminders')]
#[Description('Tell staff with the inventory permission which branches are overdue for a stock count (portal notice only, no SMS)')]
class SendStockCountReminders extends Command
{
    /**
     * Post one portal notice per eligible staff member. Reads stock only; never changes it.
     */
    public function handle(StockCountService $counts): int
    {
        $overdue = $counts->overdueBranches();
        if ($overdue->isEmpty()) {
            $this->info('No branch is overdue for a stock count. No notices sent.');

            return self::SUCCESS;
        }

        $branchCodes = $overdue->map(fn (CompanyBranch $branch): string => $branch->code)->implode(', ');
        $recipients = User::query()
            ->where('status', 'active')
            ->where(function ($users): void {
                $users->whereIn('role', ['super_admin', 'md', 'chairman', 'employee', ...array_keys(config('madina.roles', []))])
                    ->orWhere('role', 'like', 'custom_%');
            })
            ->get()
            ->filter(fn (User $user): bool => $user->allows('inventory'));

        foreach ($recipients as $user) {
            $locale = $user->preferredLocale();
            Audit::notice(
                $user->id,
                __('purchasing.notice_count_overdue_title', [], $locale),
                __('purchasing.notice_count_overdue_body', [
                    'days' => (string) StockCountService::OverdueDays,
                    'branches' => $branchCodes,
                ], $locale),
            );
        }

        $this->info("{$overdue->count()} branches overdue. Notices sent to {$recipients->count()} staff.");

        return self::SUCCESS;
    }
}
