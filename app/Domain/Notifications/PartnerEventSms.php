<?php

namespace App\Domain\Notifications;

use App\Domain\Integrations\SmsGatewayClient;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Short transactional SMS for partner and public events. Uses the configured
 * SMS gateway or the log/array driver; never posts financial entries.
 */
final class PartnerEventSms
{
    /** Messages that carry a login code or password. */
    public const SecretEvents = ['login.otp', 'account.password_reset', 'account.created'];

    /** "We got your message" texts that anyone on the public website can trigger. */
    public const PublicEvents = ['enquiry.public_received', 'partnership.application_received'];

    /** Most such texts per number per day, and for the whole site per day, so nobody can run up the SMS bill. */
    public const PublicPerPhonePerDay = 3;

    public const PublicSitePerDay = 300;

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
        if (in_array($event, self::PublicEvents, true) && ! $this->allowPublicText($normalized)) {
            return false;
        }

        return $this->deliver($normalized, $event, $message, null);
    }

    /** Only Bangladeshi mobile numbers, and within the daily limits. */
    private function allowPublicText(string $digits): bool
    {
        if (! preg_match('/^(?:88)?01[3-9]\d{8}$/D', $digits)) {
            return false;
        }
        $phoneKey = 'public-sms-phone|'.substr($digits, -11);
        if (RateLimiter::tooManyAttempts($phoneKey, self::PublicPerPhonePerDay)
            || RateLimiter::tooManyAttempts('public-sms-site', self::PublicSitePerDay)) {
            Log::warning('partner.event.sms.public_limit', ['phone' => $digits]);

            return false;
        }
        RateLimiter::hit($phoneKey, 86400);
        RateLimiter::hit('public-sms-site', 86400);

        return true;
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
            // On the live server a login code or password must never land in the log file, and an
            // undelivered login code must not count as sent.
            $secret = in_array($event, self::SecretEvents, true);
            if ($secret && app()->isProduction()) {
                Log::warning('partner.event.sms.undelivered', ['user_id' => $userId, 'phone' => $phone, 'event' => $event]);

                return false;
            }
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
