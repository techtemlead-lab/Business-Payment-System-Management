<?php

namespace App\Domain\Notifications;

use App\Domain\Integrations\SmsGatewayClient;
use App\Domain\Integrations\SmtpMailConfig;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers onboarding username/password. Prefers an enabled SMS gateway; when SMS
 * is inactive, uses enabled SMTP email; otherwise falls back to the log/array driver.
 */
final class AccountCreatedSms
{
    public function __construct(
        private SmsGatewayClient $gateway,
        private SmtpMailConfig $smtp,
    ) {}

    public function send(User $user, string $plainPassword): bool
    {
        return $this->deliverTemporaryPassword($user, $plainPassword, 'account_created', 'account_created_redacted', 'account.created');
    }

    public function sendPasswordReset(User $user, string $plainPassword): bool
    {
        return $this->deliverTemporaryPassword($user, $plainPassword, 'password_reset_by_admin', 'password_reset_by_admin_redacted', 'account.password_reset');
    }

    private function deliverTemporaryPassword(User $user, string $plainPassword, string $messageKey, string $redactedKey, string $logPrefix): bool
    {
        $phone = preg_replace('/\D+/', '', (string) $user->phone) ?: '';
        $locale = $this->localeFor($user);
        $message = __('sms.'.$messageKey, [
            'username' => $phone !== '' ? $phone : (string) $user->email,
            'password' => $plainPassword,
        ], $locale);

        if ($phone !== '' && $this->gateway->anyEnabled()) {
            $remote = $this->gateway->send($phone, $message);
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

        if ($this->smtp->enabled() && filled($user->email)) {
            $this->smtp->apply();
            try {
                Mail::raw($message, function ($mail) use ($user): void {
                    $mail->to((string) $user->email)
                        ->subject(__('portal.notice_account_invite_title', [], $this->localeFor($user)));
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

        if ($phone === '') {
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
                'message_redacted' => __('sms.'.$redactedKey, [
                    'username' => $phone,
                ], $locale),
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
