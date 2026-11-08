<?php

namespace App\Console\Commands;

use App\Domain\Accounts\PartnerAlerts;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

#[Signature('partners:progress-alerts')]
#[Description('Post portal notices about inactive direct partners, at-risk targets and ranks nearly reached (no SMS)')]
class SendPartnerProgressAlerts extends Command
{
    /**
     * Each alert reaches a partner at most once per period (the month, or the week for inactive
     * partners). Notices carry counts only, never another partner's name or phone number.
     */
    public function handle(PartnerAlerts $alerts): int
    {
        $sent = 0;
        User::query()
            ->where('status', 'active')
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->chunkById(500, function (Collection $users) use ($alerts, &$sent): void {
                $results = $alerts->forUsers($users);
                foreach ($users as $user) {
                    foreach ($results[(int) $user->id]['alerts'] as $alert) {
                        if (! Cache::add("partner-tools:alert-sent:{$user->id}:{$alert['kind']}:{$alert['period']}", true, now()->addDays(45))) {
                            continue;
                        }
                        $this->post($user, $alert);
                        $sent++;
                    }
                }
            });
        $this->info("Partner alert notices posted: {$sent}.");

        return self::SUCCESS;
    }

    /** @param array{kind: string, values: array<string, string|int>, period: string} $alert */
    private function post(User $user, array $alert): void
    {
        $locale = $user->preferredLocale();
        $values = $alert['values'];
        foreach (['rank', 'required'] as $rankField) {
            if (isset($values[$rankField])) {
                $values[$rankField] = __('portal.net_rank_'.$values[$rankField], [], $locale);
            }
        }
        Audit::notice(
            (int) $user->id,
            __('dealer_tools.alert_'.$alert['kind'].'_title', $values, $locale),
            __('dealer_tools.alert_'.$alert['kind'].'_body', $values, $locale),
        );
    }
}
