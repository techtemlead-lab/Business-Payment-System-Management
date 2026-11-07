<?php

namespace App\Domain\Notifications;

use App\Domain\Integrations\SmsGatewayClient;
use App\Domain\Security\SecuritySettings;
use App\Models\User;
use App\Support\DashboardManagement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Birthday greetings for dealers and sub-dealers: one short SMS on the day,
 * in the partner's own language and in the company's name. No sales pitch and
 * no income talk. The date of birth comes from the KYC details saved in the
 * user's profile (profile.date_of_birth).
 */
final class BirthdayGreetings
{
    public const Event = 'partner.birthday';

    public function __construct(private PartnerEventSms $sms) {}

    /** Today's date in Dhaka. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('madina.business_timezone', 'Asia/Dhaka'))->startOfDay();
    }

    /**
     * True when the stored date of birth falls on the given day. Someone born
     * on 29 February is greeted on 28 February in years without a 29th.
     */
    public static function isBirthday(mixed $dateOfBirth, CarbonImmutable $day): bool
    {
        $born = self::parseDate($dateOfBirth);
        if ($born === null) {
            return false;
        }
        if ($born->format('m-d') === $day->format('m-d')) {
            return true;
        }

        return $born->format('m-d') === '02-29' && $day->format('m-d') === '02-28' && ! $day->isLeapYear();
    }

    /** True when the partner's birthday is today (Dhaka time). Reads only the loaded profile. */
    public static function isBirthdayToday(User $user): bool
    {
        $profile = is_array($user->profile) ? $user->profile : [];

        return self::isBirthday($profile['date_of_birth'] ?? null, self::today());
    }

    /**
     * Send today's birthday SMS to every active dealer and sub-dealer, once a
     * year each. Does nothing while the owner switch is off, or while SMS cannot
     * really be sent yet (then `sms_ready` is false).
     *
     * @return array{enabled: bool, sent: int, already_sent: int, failed: int, sms_ready?: false}
     */
    public function sendForToday(?CarbonImmutable $today = null): array
    {
        $result = ['enabled' => false, 'sent' => 0, 'already_sent' => 0, 'failed' => 0];
        if (! (bool) (DashboardManagement::get()['birthday_sms_enabled'] ?? false)) {
            return $result;
        }
        if (! $this->smsReady()) {
            return [...$result, 'sms_ready' => false];
        }
        $result['enabled'] = true;
        $today ??= self::today();
        $year = (int) $today->format('Y');

        User::query()
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->where('status', 'active')
            ->whereNotNull('profile->date_of_birth')
            ->select(['id', 'name', 'phone', 'role', 'status', 'locale', 'profile'])
            ->chunkById(500, function ($partners) use ($today, $year, &$result): void {
                foreach ($partners as $partner) {
                    if (! self::isBirthday($partner->profile['date_of_birth'] ?? null, $today)) {
                        continue;
                    }
                    $outcome = $this->greet($partner, $year);
                    $result[$outcome]++;
                }
            });

        return $result;
    }

    /**
     * Claim the (user, year) slot first, then send. If the SMS cannot be sent the
     * claim is released so a later run the same day can try again.
     *
     * @return 'sent'|'already_sent'|'failed'
     */
    private function greet(User $partner, int $year): string
    {
        $claimed = DB::table('partner_birthday_greetings')->insertOrIgnore([
            'user_id' => $partner->id,
            'year' => $year,
            'sent_at' => now(),
        ]);
        if ($claimed === 0) {
            return 'already_sent';
        }

        $locale = $partner->preferredLocale();
        $message = (string) __('sms.birthday_greeting', ['name' => $this->displayName($partner, $locale)], $locale);
        try {
            $delivered = $this->sms->send($partner, self::Event, $message);
        } catch (Throwable $exception) {
            Log::warning('partner.birthday.sms_failed', ['user_id' => $partner->id, 'error' => $exception->getMessage()]);
            $delivered = false;
        }
        if (! $delivered) {
            DB::table('partner_birthday_greetings')->where('user_id', $partner->id)->where('year', $year)->delete();

            return 'failed';
        }

        return 'sent';
    }

    /** The Bangla name on file for Bangla texts, otherwise the account name. */
    private function displayName(User $partner, string $locale): string
    {
        $profile = is_array($partner->profile) ? $partner->profile : [];
        $banglaName = trim((string) ($profile['name_bn'] ?? ''));

        return $locale === 'bn' && $banglaName !== '' ? $banglaName : (string) $partner->name;
    }

    /**
     * Read a stored date of birth. The account form saves "Y-m-d"; older or
     * imported records may use day-first dates such as "15/01/1990".
     */
    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        $text = substr(trim((string) (is_scalar($value) ? $value : '')), 0, 10);
        if ($text === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $text);
            } catch (Throwable) {
                continue;
            }
            // Reject dates PHP would roll over, such as 30 February.
            if ($parsed instanceof CarbonImmutable && $parsed->format($format) === $text) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * True when a birthday SMS would really reach a phone. The owner turned the
     * greeting on (2026-11-05), but it only goes out when SMS can really be sent:
     * never in preview (trial) mode, and only through an SMS provider that is
     * switched on with its address and key. The "array" driver used by tests
     * counts as ready; the "log" driver alone does not, so a greeting is never
     * written to a log file and then marked as sent for the year.
     */
    private function smsReady(): bool
    {
        if (app(SecuritySettings::class)->previewMode()) {
            return false;
        }
        if ((string) config('madina.sms.driver', 'log') === 'array') {
            return true;
        }

        return app(SmsGatewayClient::class)->isConfigured();
    }
}
