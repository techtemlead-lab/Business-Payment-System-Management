<?php

namespace App\Domain\Payouts;

use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The weekly payout day. Owner, 2026-11-21: one payout day a week (Thursday). Owner, 2026-11-27: a partner may
 * ask for a withdrawal on any day and at any hour; only the payment waits for the payout day. Days are
 * Bangladesh calendar days (00:00 to 23:59, Asia/Dhaka). The owner sets the payout day, or switches the
 * weekly payout day off, in Finance settings. A request made on the payout day itself before the same-day
 * cut-off (14:00 unless `MADINA_WITHDRAWAL_SAME_DAY_UNTIL` says otherwise) is paid that same day; see
 * {@see self::payoutDateFor()}. Every other withdrawal rule (minimum, limits, security wait,
 * National ID, tax, holds) still applies when the request is made. Staff are not stopped from paying on
 * another day; their list only shows which requests are due.
 */
final class WithdrawalWeek
{
    public const SettingKey = 'withdrawal_week';

    public const TimeZone = 'Asia/Dhaka';

    /** ISO weekday number: 1 = Monday … 7 = Sunday. */
    public const DefaultPayoutDay = CarbonImmutable::THURSDAY;

    /**
     * Older saved values may also carry a "request_day"; it is no longer used.
     *
     * @return array{enabled: bool, payout_day: int, version: int}
     */
    public function settings(): array
    {
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR) : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? config('madina.withdrawal_weekly_window', true)),
            'payout_day' => self::weekday($stored['payout_day'] ?? null, self::DefaultPayoutDay),
            'version' => (int) ($row->version ?? 0),
        ];
    }

    /**
     * The payout day promised for a request made at this moment (Bangladesh time).
     *
     * Owner, 2026-11-27: a request made ON the payout day before the same-day cut-off is paid that same day; a
     * request made on the payout day at or after the cut-off, or on any other day, is paid on the first payout
     * weekday AFTER the request's Bangladesh day. The cut-off is `madina.withdrawal_same_day_until` (env
     * `MADINA_WITHDRAWAL_SAME_DAY_UNTIL`, "HH:MM" in 24-hour Bangladesh time, default "14:00"), so a Thursday
     * request at 10:00 is due that Thursday and one at 14:00 or 15:00 the next Thursday. "00:00" switches same-day
     * payment off. Staff may still pay earlier.
     */
    public function payoutDateFor(CarbonImmutable $requestedAt): CarbonImmutable
    {
        $local = $this->local($requestedAt);
        $payoutDay = $this->settings()['payout_day'];
        if ($local->dayOfWeekIso === $payoutDay && $local->lessThan($this->sameDayCutOff($local))) {
            return $local->startOfDay();
        }
        $day = $local->startOfDay()->addDay();

        return $day->addDays(($payoutDay - $day->dayOfWeekIso + 7) % 7);
    }

    /**
     * The moment on this Bangladesh day after which a payout-day request waits for the next payout day.
     * A missing or malformed setting falls back to 14:00.
     */
    public function sameDayCutOff(CarbonImmutable $day): CarbonImmutable
    {
        [$hour, $minute] = self::sameDayUntil();

        return $this->local($day)->startOfDay()->setTime($hour, $minute);
    }

    /**
     * When the money of a withdrawal comes, in one plain sentence ("Payment is made only on Thursday. …"),
     * or null when the weekly payout day is off. Office withdrawals are taken at the office; the other ways
     * are sent that day. A request paid the same day it was made says "today" and names the cut-off time.
     */
    public function payoutLine(string $channel, CarbonImmutable $requestedAt, ?string $locale = null): ?string
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            return null;
        }
        $payoutDate = $this->payoutDateFor($requestedAt);
        $sameDay = $payoutDate->isSameDay($this->local($requestedAt));
        $replace = ['day' => $this->dayName($settings['payout_day'], $locale), 'date' => $this->dateText($payoutDate, $locale), 'time' => $this->cutOffText($locale)];
        $key = $channel === WithdrawalMethods::Office ? 'office_withdrawal.payout_line_office' : 'office_withdrawal.payout_line_online';

        return __($sameDay ? $key.'_today' : $key, $replace, $locale);
    }

    /**
     * What the partner's withdraw sheet needs: whether the weekly payout day is on, its name, the date the
     * money of a request made now comes, whether that is today, and the same-day cut-off time.
     *
     * @return array{enabled: bool, payout_day: string, payout_date: string, same_day: bool, same_day_until: ?string}
     */
    public function partnerInfo(?CarbonImmutable $at = null): array
    {
        $settings = $this->settings();
        $now = $this->local($at);
        $payoutDate = $this->payoutDateFor($now);

        return [
            'enabled' => $settings['enabled'],
            'payout_day' => $this->dayName($settings['payout_day']),
            'payout_date' => $this->dateText($payoutDate),
            'same_day' => $payoutDate->isSameDay($now),
            'same_day_until' => $this->sameDayUntilText(),
        ];
    }

    public function save(User $actor, bool $enabled, int $payoutDay, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $enabled, $payoutDay, $version, $reason): void {
            $fresh = $actor->fresh();
            abort_unless($fresh !== null && $fresh->owner() && $fresh->canAct('finance', 'edit'), 403);
            $before = $this->settings();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            if ($payoutDay < 1 || $payoutDay > 7) {
                throw ValidationException::withMessages(['payout_day' => __('office_withdrawal.err_weekday')]);
            }
            $after = ['enabled' => $enabled, 'payout_day' => $payoutDay];
            $existing = DB::table('business_settings')->where('key', self::SettingKey)->first();
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingKey], [
                'value' => json_encode($after, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'withdrawal.week_changed', self::SettingKey, [
                'before' => array_diff_key($before, ['version' => true]),
                'after' => $after,
                'reason' => $reason,
            ]);
        }, 3);
    }

    /** A weekday's name in the reader's language ("বৃহস্পতিবার", "Thursday"). */
    public function dayName(int $isoDay, ?string $locale = null): string
    {
        return CarbonImmutable::create(2026, 1, 5, 0, 0, 0, self::TimeZone)->addDays($isoDay - 1)->locale($locale ?? app()->getLocale())->isoFormat('dddd');
    }

    /** The same-day cut-off as the reader says it, or null when same-day payment is switched off ("00:00"). */
    public function sameDayUntilText(?string $locale = null): ?string
    {
        return self::sameDayUntil() === [0, 0] ? null : $this->cutOffText($locale);
    }

    /** The same-day cut-off as the reader says it ("2:00 PM", "দুপুর ২:০০"). */
    public function cutOffText(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        [$hour, $minute] = self::sameDayUntil();

        // Bangla says the part of the day first ("দুপুর ২:০০"); English after ("2:00 PM").
        return LocalDigits::text(CarbonImmutable::create(2026, 1, 1, $hour, $minute, 0, self::TimeZone)->locale($locale)->isoFormat($locale === 'bn' ? 'A h:mm' : 'h:mm A'), $locale);
    }

    /**
     * The configured same-day cut-off as [hour, minute]; 14:00 when the setting is missing or malformed.
     *
     * @return array{0: int, 1: int}
     */
    private static function sameDayUntil(): array
    {
        $value = trim((string) config('madina.withdrawal_same_day_until', '14:00'));
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/D', $value, $match) !== 1) {
            return [14, 0];
        }

        return [(int) $match[1], (int) $match[2]];
    }

    private function dateText(CarbonImmutable $date, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return LocalDigits::text($date->locale($locale)->isoFormat('D MMMM YYYY'), $locale);
    }

    private function local(?CarbonImmutable $at): CarbonImmutable
    {
        return ($at ?? CarbonImmutable::now())->setTimezone(self::TimeZone);
    }

    private static function weekday(mixed $value, int $default): int
    {
        $day = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($day) && $day >= 1 && $day <= 7 ? $day : $default;
    }
}
