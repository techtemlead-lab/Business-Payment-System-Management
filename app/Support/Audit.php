<?php

namespace App\Support;

use App\Domain\Notifications\PartnerEventEmail;
use App\Domain\Notifications\WebPushNotices;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function record(?int $actor, string $action, string $subject, array $metadata = []): void
    {
        if (! isset($metadata['correlation_id']) && function_exists('request')) {
            $req = request();
            $correlationId = $req?->attributes?->get('correlation_id') ?: $req?->header('X-Correlation-ID');
            if ($correlationId) {
                $metadata['correlation_id'] = (string) $correlationId;
            }
        }

        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => $action, 'subject' => $subject, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    /**
     * @param  ?string  $emailEvent  When set, the same text also goes out by email if that event's email is on (see PartnerEventEmail).
     */
    public static function notice(int $user, string $title, string $body, ?string $emailEvent = null): void
    {
        DB::table('account_notices')->insert(['user_id' => $user, 'title' => $title, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        app(WebPushNotices::class)->send([['user_id' => $user, 'title' => $title, 'body' => $body]]);
        if ($emailEvent !== null) {
            app(PartnerEventEmail::class)->send($user, $emailEvent, $title, $body);
        }
    }

    /**
     * Notify administrators, owners and staff members matching specific capabilities.
     *
     * @param  string|list<string>  $capabilities
     */
    public static function notifyAdminsAndStaff(string|array $capabilities, string $title, string $body, ?int $excludeUserId = null): void
    {
        $recipients = self::officeRecipients($capabilities, $excludeUserId);

        if ($recipients->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $recipients->map(fn (User $u): array => [
            'user_id' => $u->id,
            'title' => $title,
            'body' => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        DB::table('account_notices')->insert($rows);
        app(WebPushNotices::class)->send($rows);
    }

    /**
     * The active owners, administrators and staff holding one of the capabilities.
     *
     * @param  string|list<string>  $capabilities
     * @return Collection<int, User>
     */
    public static function officeRecipients(string|array $capabilities, ?int $excludeUserId = null): Collection
    {
        $caps = (array) $capabilities;
        $users = User::query()
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereIn('role', ['super_admin', 'md', 'chairman'])
                    ->orWhereIn('role', ['employee', ...array_keys(config('madina.roles', []))])
                    ->orWhere('role', 'like', 'custom_%');
            })
            ->get();

        return $users->filter(function (User $u) use ($caps, $excludeUserId): bool {
            if ($excludeUserId && (int) $u->id === (int) $excludeUserId) {
                return false;
            }
            if ($u->owner() || $u->role === 'super_admin') {
                return true;
            }
            foreach ($caps as $cap) {
                if ($u->allows($cap)) {
                    return true;
                }
            }

            return false;
        })->values();
    }
}
