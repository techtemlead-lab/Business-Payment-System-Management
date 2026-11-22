<?php

namespace App\Domain\Notifications;

use App\Domain\Integrations\SmsGatewayClient;
use App\Domain\Integrations\SmtpMailConfig;
use App\Mail\EventNoticeMail;
use App\Models\User;
use App\Support\PortalHost;
use App\Support\RedactedError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a new account's username and password by SMS, and a reset password by SMS or
 * enabled SMTP email. On a server with no SMS service it falls back to the log/array driver.
 */
final class AccountCreatedSms
{
    public function __construct(
        private SmsGatewayClient $gateway,
        private SmtpMailConfig $smtp,
    ) {}

    public function send(User $user, string $plainPassword): bool
    {
        return $this->deliverTemporaryPassword($user, $plainPassword, 'account_created', 'account.created', true);
    }

    /**
     * The "your account is ready" email, carrying the username and the first password (client, 2026-11-22).
     * It leaves at once and is not queued, so the password never sits in the job table. Not sent when the
     * person has no real address or no real mail service is set up; the caller then falls back to the
     * plain notice. Never throws: a mail problem must not undo a created account.
     */
    public function sendWelcomeEmail(User $user, string $plainPassword): bool
    {
        if (! PartnerEventEmail::isRealAddress($user->email)) {
            return false;
        }
        $address = trim((string) $user->email);
        try {
            $this->smtp->apply();
            Mail::purge();
            if (! PartnerEventEmail::realMailConfigured()) {
                return false;
            }
            $phone = preg_replace('/\D+/', '', (string) $user->phone) ?: '';
            $locale = $this->localeFor($user);
            $portalUrl = PortalHost::url(PortalHost::forUser($user), '/');
            Mail::to($address)->send(new EventNoticeMail(
                noticeTitle: (string) __('event_emails.account_created_title', [], $locale),
                noticeBody: (string) __('event_emails.account_created_credentials_body', [
                    'username' => $phone !== '' ? $phone : $address,
                    'password' => $plainPassword,
                ], $locale),
                recipientName: (string) $user->name,
                portalUrl: $portalUrl,
                language: $locale,
            ));
            Log::info('account.created.mail.welcome', ['user_id' => $user->id, 'email' => $address, 'password_delivered' => true]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('account.created.mail.welcome_failed', ['user_id' => $user->id, 'error' => RedactedError::message($exception)]);

            return false;
        }
    }

    public function sendPasswordReset(User $user, string $plainPassword): bool
    {
        return $this->deliverTemporaryPassword($user, $plainPassword, 'password_reset_by_admin', 'account.password_reset');
    }

    /**
     * @param  bool  $smsOnly  A new person's password goes by SMS or not at all, so a failed SMS never turns into
     *                         a plain password in an email. The caller sends a set-your-password link instead.
     */
    private function deliverTemporaryPassword(User $user, string $plainPassword, string $messageKey, string $logPrefix, bool $smsOnly = false): bool
    {
        $phone = preg_replace('/\D+/', '', (string) $user->phone) ?: '';
        $locale = $this->localeFor($user);
        $replace = [
            'username' => $phone !== '' ? $phone : (string) $user->email,
            'password' => $plainPassword,
        ];
        $message = SmsText::make('sms.'.$messageKey, $replace);
        $refused = false;

        if ($phone !== '' && $this->gateway->anyEnabled()) {
            $remote = $this->gateway->send($phone, $message);
            $refused = $remote['refused'];
            if ($remote['sent']) {
                Log::info($logPrefix.'.sms.gateway', [
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'provider' => $remote['provider'],
                    'password_delivered' => true,
                ]);

                return true;
            }
        }

        if ($smsOnly && ($this->gateway->anyEnabled() || app()->isProduction())) {
            Log::warning($logPrefix.'.sms.undelivered', ['user_id' => $user->id, 'phone' => $phone]);

            return false;
        }

        if (! $smsOnly && $this->smtp->enabled() && filled($user->email)) {
            $this->smtp->apply();
            try {
                // The email's words can be re-worded in the message templates; with no saved text it is today's text.
                $text = MessageTemplates::email(MessageTemplateCatalog::CredentialsMailKey, $locale, [
                    ...$replace,
                    'name' => (string) $user->name,
                    'link' => PortalHost::url(PortalHost::forUser($user), '/'),
                    'company' => (string) (config('mail.from.name') ?: config('app.name')),
                ], (string) __('portal.notice_account_invite_title', [], $locale), (string) __('sms.'.$messageKey, $replace, $locale));
                Mail::raw($text['body'], function ($mail) use ($user, $text): void {
                    $mail->to((string) $user->email)->subject($text['subject']);
                });
                Log::info($logPrefix.'.mail.smtp', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'password_delivered' => true,
                ]);

                return true;
            } catch (\Throwable $exception) {
                Log::warning($logPrefix.'.mail.failed', [
                    'user_id' => $user->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($phone === '' || $refused) {
            return false;
        }

        $driver = (string) config('madina.sms.driver', 'log');
        if ($driver === 'log' || $driver === 'array') {
            if (app()->environment('production') && $driver === 'log') {
                Log::critical($logPrefix.'.sms.log_driver_in_production', [
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'message' => 'MADINA_SMS_DRIVER=log will not deliver real SMS in production. Enable an SMS provider or SMTP under Service integrations.',
                ]);
            }
            Log::channel((string) config('madina.sms.log_channel', 'stack'))->info($logPrefix.'.sms', [
                'user_id' => $user->id,
                'phone' => $phone,
                'password_delivered' => true,
                // The same template as the sent text, with the password hidden, so the log shows what really goes out.
                'message_redacted' => SmsText::make('sms.'.$messageKey, [
                    'username' => $phone,
                    'password' => '[REDACTED]',
                ]),
            ]);
            if ($driver === 'array') {
                $bag = config('madina.sms.sent', []);
                $bag[] = ['user_id' => $user->id, 'phone' => $phone, 'message' => $message];
                config(['madina.sms.sent' => $bag]);
            }

            return true;
        }

        Log::warning($logPrefix.'.sms.unsupported_driver', ['driver' => $driver]);

        return false;
    }

    private function localeFor(User $user): string
    {
        $locale = is_string($user->locale ?? null) ? $user->locale : app()->getLocale();
        $supported = config('madina.locales', ['en', 'bn']);

        return in_array($locale, $supported, true) ? $locale : (string) config('app.fallback_locale', 'en');
    }
}
