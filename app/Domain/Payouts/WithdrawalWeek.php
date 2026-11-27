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
 * weekly payout day off, in Finance settings. Every other withdrawal rule (minimum, limits, security wait,
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
     * The payout day promised for a request made at this moment: the first payout weekday AFTER the request's
     * Bangladesh day. A request made on the payout day itself is paid on the next week's payout day. There is
     * no cut-off hour, and a same-day promise could not be kept for a request sent in the evening, after the
     * office has closed, or before staff have checked and approved it; the old Wednesday-to-Thursday rule also
     * always left the office one day to approve. Staff may still pay earlier.
     */
    public function payoutDateFor(CarbonImmutable $requestedAt): CarbonImmutable
    {
        $day = $this->local($requestedAt)->startOfDay()->addDay();

        return $day->addDays(($this->settings()['payout_day'] - $day->dayOfWeekIso + 7) % 7);
    }

    /**
     * When the money of a withdrawal comes, in one plain sentence ("Payment is made only on Thursday. …"),
     * or null when the weekly payout day is off. Office withdrawals are taken at the office; the other ways
     * are sent that day.
     */
    public function payoutLine(string $channel, CarbonImmutable $requestedAt, ?string $locale = null): ?string
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            return null;
        }
        $replace = ['day' => $this->dayName($settings['payout_day'], $locale), 'date' => $this->dateText($this->payoutDateFor($requestedAt), $locale)];

        return __($channel === WithdrawalMethods::Office ? 'office_withdrawal.payout_line_office' : 'office_withdrawal.payout_line_online', $replace, $locale);
    }

    /**
     * What the partner's withdraw sheet needs: whether the weekly payout day is on, its name, and the date
     * the money of a request made now comes.
     *
     * @return array{enabled: bool, payout_day: string, payout_date: string}
     */
    public function partnerInfo(?CarbonImmutable $at = null): array
    {
        $settings = $this->settings();

        return [
            'enabled' => $settings['enabled'],
            'payout_day' => $this->dayName($settings['payout_day']),
            'payout_date' => $this->dateText($this->payoutDateFor($this->local($at))),
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
