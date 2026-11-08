<?php

namespace App\Domain\Notifications;

use App\Support\RedactedError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A short record of emails the mail server would not take, kept for staff on the Service
 * integrations page. It keeps a masked address and never the message text. A failure to
 * record one never stops anything else.
 */
final class EmailDeliveryLog
{
    private const KeepDays = 90;

    private const ReasonLength = 200;

    public static function failed(string $event, string $email, \Throwable $exception): void
    {
        $masked = self::maskedAddress($email);
        $reason = RedactedError::text(str_replace($email, $masked, $exception->getMessage()));
        Log::warning('email.delivery_failed', ['event' => $event, 'recipient' => $masked, 'error' => $reason]);
        try {
            DB::table('email_delivery_failures')->insert([
                'event' => $event,
                'recipient_masked' => $masked,
                'reason' => mb_strimwidth($reason, 0, self::ReasonLength, '…'),
                'created_at' => now(),
            ]);
            DB::table('email_delivery_failures')->where('created_at', '<', now()->subDays(self::KeepDays))->delete();
        } catch (\Throwable $logFailure) {
            Log::warning('email.delivery_log_failed', ['error' => RedactedError::message($logFailure)]);
        }
    }

    /**
     * The newest failures, for the Service integrations page.
     *
     * @return list<array{id: int, event: string, recipient: string, reason: ?string, created_at: string}>
     */
    public static function recentFailures(int $limit = 20): array
    {
        return DB::table('email_delivery_failures')->orderByDesc('id')->limit($limit)
            ->get(['id', 'event', 'recipient_masked', 'reason', 'created_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'event' => EmailEventSwitches::label((string) $row->event),
                'recipient' => (string) $row->recipient_masked,
                'reason' => $row->reason !== null ? (string) $row->reason : null,
                'created_at' => (string) $row->created_at,
            ])->values()->all();
    }

    public static function maskedAddress(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '•••';
        }
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 2).'•••@'.$domain;
    }
}
