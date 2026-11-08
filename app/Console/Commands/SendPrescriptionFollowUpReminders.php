<?php

namespace App\Console\Commands;

use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionFollowUps;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('prescriptions:follow-up-reminders')]
#[Description('Tell each prescriber how many patients are due today or past their follow-up date (portal notice only, no SMS)')]
class SendPrescriptionFollowUpReminders extends Command
{
    /**
     * Posts at most one notice per prescriber per day. The notice carries counts only, never
     * a patient's name, because it can also appear on a phone's lock screen.
     */
    public function handle(PrescriptionFollowUps $followUps): int
    {
        if (! PrescriptionAccess::enabled()) {
            $this->info('Prescriptions are switched off. No notices sent.');

            return self::SUCCESS;
        }
        $counts = $followUps->countsByPrescriber();
        $today = $followUps->today();
        $prescribers = User::query()
            ->whereIn('id', array_keys($counts))
            ->get()
            ->filter(fn (User $user): bool => PrescriptionAccess::canPrescribe($user));

        $sent = 0;
        foreach ($prescribers as $prescriber) {
            if (! Cache::add("prescriptions:follow-up-notice:{$today}:{$prescriber->id}", true, now()->addDays(2))) {
                continue;
            }
            $locale = $prescriber->preferredLocale();
            Audit::notice(
                $prescriber->id,
                __('prescription.notice_follow_up_title', [], $locale),
                __('prescription.notice_follow_up_body', [
                    'today' => $counts[$prescriber->id]['due_today'],
                    'overdue' => $counts[$prescriber->id]['overdue'],
                ], $locale),
            );
            $sent++;
        }
        $this->info("Follow-up notices sent to {$sent} prescribers.");

        return self::SUCCESS;
    }
}
