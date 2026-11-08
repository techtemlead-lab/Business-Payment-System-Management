<?php

namespace App\Domain\Notifications;

use App\Jobs\SendEventEmail;
use App\Models\User;
use App\Support\RedactedError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The email twin of PartnerEventSms. It queues one email for an event when a real mail
 * transport is set up, the owner has not switched that event off, and the person has a
 * real address. The text is the portal notice, already in the person's language. It never
 * carries a password or login code, and nothing here can stop the action that triggered it.
 */
final class PartnerEventEmail
{
    /** Hosts that only exist on a developer's machine (the same list as the readiness check). */
    private const DevelopmentSmtpHosts = ['mail', 'localhost', '127.0.0.1', '::1', 'mailpit', 'mailhog', 'smtp'];

    private const FakeAddressSuffixes = ['.test', '.invalid', '.example', '.localhost', '.local'];

    private const FakeAddressDomains = ['example.com', 'example.net', 'example.org'];

    public function __construct(private EmailEventSwitches $switches) {}

    public function send(User|int $recipient, string $event, string $title, string $body): void
    {
        try {
            if (! self::realMailConfigured() || ! $this->switches->isOn($event)) {
                return;
            }
            $user = $recipient instanceof User ? $recipient : User::query()->find($recipient);
            if (! $user instanceof User || ! self::isRealAddress($user->email)) {
                return;
            }
            $userId = (int) $user->id;
            // Queued only after the action is saved, so a rolled-back action sends nothing.
            DB::afterCommit(function () use ($userId, $event, $title, $body): void {
                try {
                    SendEventEmail::dispatch($userId, $event, $title, $body);
                } catch (\Throwable $exception) {
                    Log::warning('email.not_queued', ['event' => $event, 'error' => RedactedError::message($exception)]);
                }
            });
        } catch (\Throwable $exception) {
            Log::warning('email.not_queued', ['event' => $event, 'error' => RedactedError::message($exception)]);
        }
    }

    /**
     * For events with no portal notice to copy: the text comes from language keys, in the person's language.
     *
     * @param  array<string, mixed>  $replace
     */
    public function sendTranslated(User $user, string $event, string $titleKey, string $bodyKey, array $replace = []): void
    {
        $locale = $user->preferredLocale();
        $this->send($user, $event, (string) __($titleKey, $replace, $locale), (string) __($bodyKey, $replace, $locale));
    }

    /**
     * True only when mail would really leave the building: not the log or array transport,
     * a usable From address, and for SMTP a host that is not a developer's local one.
     */
    public static function realMailConfigured(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config('mail.mailers.'.$mailer.'.transport', $mailer);
        if (in_array($transport, ['log', 'array', 'null', ''], true)) {
            return false;
        }
        $from = strtolower((string) config('mail.from.address'));
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || str_ends_with($from, '@example.com')) {
            return false;
        }
        if ($transport === 'smtp') {
            $host = strtolower(trim((string) config('mail.mailers.'.$mailer.'.host')));

            return $host !== '' && ! in_array($host, self::DevelopmentSmtpHosts, true);
        }

        return true;
    }

    /** False for empty, malformed, reserved test and "removed for privacy" addresses. */
    public static function isRealAddress(?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $domain = substr($email, (int) strrpos($email, '@') + 1);
        foreach (self::FakeAddressSuffixes as $suffix) {
            if (str_ends_with('.'.$domain, $suffix)) {
                return false;
            }
        }

        return ! in_array($domain, self::FakeAddressDomains, true);
    }
}
