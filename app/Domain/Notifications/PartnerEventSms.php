<?php

namespace App\Domain\Notifications;

use App\Domain\Integrations\SmsGatewayClient;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Short transactional SMS for partner and public events. Uses the configured
 * SMS gateway or the log/array driver; never posts financial entries.
 */
final class PartnerEventSms
{
    public function __construct(private SmsGatewayClient $gateway) {}

    public function send(User $user, string $event, string $message): bool
    {
        $phone = preg_replace('/\D+/', '', (string) $user->phone) ?: '';
        if ($phone === '') {
            return false;
        }

        return $this->deliver($phone, $event, $message, $user->id);
    }

    public function sendToPhone(string $phone, string $event, string $message): bool
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?: '';
        if ($normalized === '') {
            return false;
        }

        return $this->deliver($normalized, $event, $message, null);
    }

    private function deliver(string $phone, string $event, string $message, ?int $userId): bool
    {
        $remote = $this->gateway->send($phone, $message);
        if ($remote['sent']) {
            Log::info('partner.event.sms.gateway', [
                'user_id' => $userId,
                'phone' => $phone,
                'event' => $event,
                'provider' => $remote['provider'],
            ]);

            return true;
        }

        $driver = (string) config('madina.sms.driver', 'log');
        if ($driver === 'log' || $driver === 'array') {
            Log::channel((string) config('madina.sms.log_channel', 'stack'))->info('partner.event.sms', [
                'user_id' => $userId,
                'phone' => $phone,
                'event' => $event,
                'message' => $message,
            ]);
            if ($driver === 'array') {
                $bag = config('madina.sms.sent', []);
                $bag[] = ['user_id' => $userId, 'phone' => $phone, 'event' => $event, 'message' => $message];
                config(['madina.sms.sent' => $bag]);
            }

            return true;
        }

        Log::warning('partner.event.sms.unsupported_driver', [
            'driver' => $driver,
            'event' => $event,
            'gateway' => $remote['message'],
        ]);

        return false;
    }
}
