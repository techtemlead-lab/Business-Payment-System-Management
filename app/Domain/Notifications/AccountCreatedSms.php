<?php

namespace App\Domain\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Delivers the onboarding username/password SMS. Uses a log driver until a
 * production Bangladesh SMS gateway is configured.
 */
final class AccountCreatedSms
{
    public function send(User $user, string $plainPassword): bool
    {
        $phone = preg_replace('/\D+/', '', (string) $user->phone) ?: '';
        if ($phone === '') {
            return false;
        }

        $message = sprintf(
            'Madina account created. Username: %s Password: %s. Please sign in and change your password.',
            $phone,
            $plainPassword
        );

        $driver = (string) config('madina.sms.driver', 'log');
        if ($driver === 'log' || $driver === 'array') {
            Log::channel((string) config('madina.sms.log_channel', 'stack'))->info('account.created.sms', [
                'user_id' => $user->id,
                'phone' => $phone,
                'message' => $message,
            ]);
            if ($driver === 'array') {
                $bag = config('madina.sms.sent', []);
                $bag[] = ['user_id' => $user->id, 'phone' => $phone, 'message' => $message];
                config(['madina.sms.sent' => $bag]);
            }

            return true;
        }

        Log::warning('account.created.sms.unsupported_driver', ['driver' => $driver]);

        return false;
    }
}
