<?php

namespace App\Domain\Payouts;

use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The weekly withdrawal day (owner, 2026-11-21): a partner may ask for money only on the application
 * day (Wednesday) and is paid on the payout day (Thursday), once a week, whatever the way of payment.
 * Days are Bangladesh calendar days (00:00 to 23:59, Asia/Dhaka). The owner sets both days and may
 * switch the rule off in Finance settings; every other withdrawal rule (minimum, limits, security
 * wait, National ID, tax, holds) still applies on the application day.
 */
final class WithdrawalWeek
{
    public const SettingKey = 'withdrawal_week';

    public const TimeZone = 'Asia/Dhaka';

    /** ISO weekday numbers: 1 = Monday … 7 = Sunday. */
    public const DefaultRequestDay = CarbonImmutable::WEDNESDAY;

    public const DefaultPayoutDay = CarbonImmutable::THURSDAY;

    /**
     * @return array{enabled: bool, request_day: int, payout_day: int, version: int}
     */
    public function settings(): array
    {
        $row = DB::table('business_settings')->where('key', self::SettingKey)->first();
        $stored = $row ? json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR) : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? config('madina.withdrawal_weekly_window', true)),
            'request_day' => self::weekday($stored['request_day'] ?? null, self::DefaultRequestDay),
            'payout_day' => self::weekday($stored['payout_day'] ?? null, self::DefaultPayoutDay),
            'version' => (int) ($row->version ?? 0),
        ];
    }

    /** True when a withdrawal may be asked for at this moment (always, when the owner switched the rule off). */
    public function isOpen(?CarbonImmutable $at = null): bool
    {
        $settings = $this->settings();

        return ! $settings['enabled'] || $this->local($at)->dayOfWeekIso === $settings['request_day'];
    }

    /** The next application day: today when today is one, otherwise the coming one. */
    public function nextRequestDate(?CarbonImmutable $at = null): CarbonImmutable
    {
        return $this->onOrAfter($this->local($at)->startOfDay(), $this->settings()['request_day']);
    }

    /** The payout day for a request made at this moment: the first payout weekday on or after the request's day. */
    public function payoutDateFor(CarbonImmutable $requestedAt): CarbonImmutable
    {
        return $this->onOrAfter($this->local($requestedAt)->startOfDay(), $this->settings()['payout_day']);
    }

    /**
     * The plain sentence shown instead of the request button, and as the server's refusal: which day to
     * apply, which day the money comes, and the date of the next application day.
     */
    public function closedMessage(?CarbonImmutable $at = null, ?string $locale = null): string
    {
        $settings = $this->settings();

        return __('office_withdrawal.week_closed', [
            'request_day' => $this->dayName($settings['request_day'], $locale),
            'payout_day' => $this->dayName($settings['payout_day'], $locale),
        ], $locale).' '.__('office_withdrawal.week_next', ['date' => $this->dateText($this->nextRequestDate($at), $locale)], $locale);
    }

    /**
     * Refuses a request made outside the application day and keeps a record of the refusal. Call it
     * outside the request's database transaction, so the record is kept.
     *
     * @throws ValidationException
     */
    public function refuseOutsideWindow(User $partner, ?CarbonImmutable $at = null): void
    {
        if ($this->isOpen($at)) {
            return;
        }
        Audit::record($partner->id, 'withdrawal.refused_weekday', (string) $partner->id, [
            'next_request_date' => $this->nextRequestDate($at)->toDateString(),
        ]);

        throw ValidationException::withMessages(['amount' => $this->closedMessage($at)]);
    }

    /**
     * When the money of a withdrawal comes, in one plain sentence, or null when the weekly rule is off.
     * Office withdrawals are taken at the office; the other ways are sent that day.
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
     * What the partner's withdraw sheet needs: whether a request can be made now, the sentence shown
     * when it cannot, and when the money of a request made now (or on the next application day) comes.
     *
     * @return array{enabled: bool, open: bool, message: string|null, request_day: string, payout_day: string, next_request_date: string, payout_date: string}
     */
    public function partnerInfo(?CarbonImmutable $at = null): array
    {
        $settings = $this->settings();
        $open = $this->isOpen($at);
        $next = $this->nextRequestDate($at);

        return [
            'enabled' => $settings['enabled'],
            'open' => $open,
            'message' => $open ? null : $this->closedMessage($at),
            'request_day' => $this->dayName($settings['request_day']),
            'payout_day' => $this->dayName($settings['payout_day']),
            'next_request_date' => $this->dateText($next),
            'payout_date' => $this->dateText($this->payoutDateFor($open ? $this->local($at) : $next)),
        ];
    }

    public function save(User $actor, bool $enabled, int $requestDay, int $payoutDay, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $enabled, $requestDay, $payoutDay, $version, $reason): void {
            $fresh = $actor->fresh();
            abort_unless($fresh !== null && $fresh->owner() && $fresh->canAct('finance', 'edit'), 403);
            $before = $this->settings();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_fin_wd_stale')]);
            }
            foreach (['request_day' => $requestDay, 'payout_day' => $payoutDay] as $field => $day) {
                if ($day < 1 || $day > 7) {
                    throw ValidationException::withMessages([$field => __('office_withdrawal.err_weekday')]);
                }
            }
            $after = ['enabled' => $enabled, 'request_day' => $requestDay, 'payout_day' => $payoutDay];
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

    /** A weekday's name in the reader's language ("বুধবার", "Wednesday"). */
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

    private function onOrAfter(CarbonImmutable $day, int $isoWeekday): CarbonImmutable
    {
        return $day->addDays(($isoWeekday - $day->dayOfWeekIso + 7) % 7);
    }

    private static function weekday(mixed $value, int $default): int
    {
        $day = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($day) && $day >= 1 && $day <= 7 ? $day : $default;
    }
}
