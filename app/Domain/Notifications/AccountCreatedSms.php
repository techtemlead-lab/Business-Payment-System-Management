<?php

namespace App\Domain\Notifications;

use App\Domain\Integrations\SmsGatewayClient;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Delivers the onboarding username/password SMS. Uses the log/array driver until
 * an administrator enables an SMS provider under Service integrations.
 */
final class AccountCreatedSms
{
    public function __construct(private SmsGatewayClient $gateway) {}

    public function send(User $user, string $plainPassword): bool
    {
        $phone = preg_replace('/\D+/', '', (string) $user->phone) ?: '';
        if ($phone === '') {
            return false;
        }

        $locale = $this->localeFor($user);
        $message = __('sms.account_created', [
            'username' => $phone,
            'password' => $plainPassword,
        ], $locale);

        $remote = $this->gateway->send($phone, $message);
        if ($remote['sent']) {
            Log::info('account.created.sms.gateway', [
                'user_id' => $user->id,
                'phone' => $phone,
                'provider' => $remote['provider'],
                'password_delivered' => true,
            ]);

            return true;
        }

        $driver = (string) config('madina.sms.driver', 'log');
        if ($driver === 'log' || $driver === 'array') {
            if (app()->environment('production') && $driver === 'log') {
                Log::critical('account.created.sms.log_driver_in_production', [
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'message' => 'MADINA_SMS_DRIVER=log will not deliver real SMS in production. Enable an SMS provider under Service integrations.',
                ]);
            }
            // Never persist plaintext passwords in application logs.
            Log::channel((string) config('madina.sms.log_channel', 'stack'))->info('account.created.sms', [
                'user_id' => $user->id,
                'phone' => $phone,
                'password_delivered' => true,
                'message_redacted' => __('sms.account_created_redacted', [
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

        Log::warning('account.created.sms.unsupported_driver', ['driver' => $driver, 'gateway' => $remote['message']]);

        return false;
    }

    private function localeFor(User $user): string
    {
        $locale = is_string($user->locale ?? null) ? $user->locale : app()->getLocale();
        $supported = config('madina.locales', ['en', 'bn']);

        return in_array($locale, $supported, true) ? $locale : (string) config('app.fallback_locale', 'en');
    }
}
