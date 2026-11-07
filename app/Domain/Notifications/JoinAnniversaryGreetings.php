<?php

namespace App\Domain\Notifications;

use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A short in-app notice to each active dealer and sub-dealer on the day they joined,
 * one or more years ago. No SMS, so it costs nothing; at most one greeting a year each.
 */
final class JoinAnniversaryGreetings
{
    /** Today's date at midnight in business time (Asia/Dhaka). */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /**
     * Greet everyone whose join anniversary is today.
     *
     * @return array{sent: int, already_sent: int}
     */
    public function sendForToday(?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $year = (int) $today->format('Y');
        $result = ['sent' => 0, 'already_sent' => 0];
        $days = [(int) $today->format('j')];
        if ($today->format('m-d') === '02-28' && ! $today->isLeapYear()) {
            $days[] = 29;
        }
        $joinedDate = "(users.created_at AT TIME ZONE 'UTC' AT TIME ZONE ?)";

        User::query()
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->where('status', 'active')
            ->whereRaw("EXTRACT(MONTH FROM {$joinedDate}) = ?", [self::timezone(), (int) $today->format('n')])
            ->whereRaw("EXTRACT(DAY FROM {$joinedDate}) IN (".implode(',', array_fill(0, count($days), '?')).')', [self::timezone(), ...$days])
            ->whereRaw("EXTRACT(YEAR FROM {$joinedDate}) < ?", [self::timezone(), $year])
            ->select(['id', 'name', 'role', 'locale', 'profile', 'created_at'])
            ->chunkById(500, function ($partners) use ($today, $year, &$result): void {
                foreach ($partners as $partner) {
                    $result[$this->greet($partner, $today, $year)]++;
                }
            });

        return $result;
    }

    /**
     * Claim the (user, year) slot first so a second run the same year sends nothing.
     *
     * @return 'sent'|'already_sent'
     */
    private function greet(User $partner, CarbonImmutable $today, int $year): string
    {
        $claimed = DB::table('partner_anniversary_greetings')->insertOrIgnore([
            'user_id' => $partner->id,
            'year' => $year,
            'sent_at' => now(),
        ]);
        if ($claimed === 0) {
            return 'already_sent';
        }
        $years = $year - (int) $partner->created_at->timezone(self::timezone())->format('Y');
        $locale = $partner->preferredLocale();
        $profile = is_array($partner->profile) ? $partner->profile : [];
        $banglaName = trim((string) ($profile['name_bn'] ?? ''));
        $name = $locale === 'bn' && $banglaName !== '' ? $banglaName : (string) $partner->name;
        Audit::notice(
            $partner->id,
            __('portal.notice_join_anniversary_title', [], $locale),
            trans_choice('portal.notice_join_anniversary_body', $years, ['name' => $name, 'years' => $years], $locale),
        );

        return 'sent';
    }

    private static function timezone(): string
    {
        return (string) config('madina.business_timezone', 'Asia/Dhaka');
    }
}
