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

        $message = sprintf(
            'Madina account created. Username: %s Password: %s. Please sign in and change your password.',
            $phone,
            $plainPassword
        );

        $remote = $this->gateway->send($phone, $message);
        if ($remote['sent']) {
            Log::info('account.created.sms.gateway', [
                'user_id' => $user->id,
                'phone' => $phone,
                'provider' => $remote['provider'],
            ]);

            return true;
        }

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

        Log::warning('account.created.sms.unsupported_driver', ['driver' => $driver, 'gateway' => $remote['message']]);

        return false;
    }
}
