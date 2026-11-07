<?php

namespace App\Domain\Notifications;

use App\Models\User;
use App\Notifications\AccountNoticePush;
use Illuminate\Http\Request;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Phone/browser push for portal notices (account_notices).
 *
 * The in-app notice is always written first; this only adds a push copy for users who
 * turned notifications on in their browser. With no VAPID keys the feature is off and
 * nothing here touches the database.
 */
final class WebPushNotices
{
    /** Session key holding the endpoint this browser subscribed with. */
    public const SessionEndpoint = 'web_push_endpoint';

    public static function enabled(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    /**
     * True when the endpoint is an https address on a known browser push service
     * (config madina.web_push_hosts or one of its subdomains). Other addresses are refused,
     * because the server later posts to the saved endpoint.
     */
    public static function isPushServiceEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }
        foreach ((array) config('madina.web_push_hosts', []) as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }

    public static function publicKey(): ?string
    {
        return self::enabled() ? (string) config('webpush.vapid.public_key') : null;
    }

    /**
     * Push a copy of freshly written notices to the users who have a subscription.
     *
     * @param  iterable<array{user_id: int, title: string, body: string}>  $notices
     */
    public function send(iterable $notices): void
    {
        if (! self::enabled()) {
            return;
        }
        $notices = collect($notices)->values();
        if ($notices->isEmpty()) {
            return;
        }

        $users = User::query()
            ->whereIn('id', $notices->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->all())
            ->where('status', 'active')
            ->whereHas('pushSubscriptions')
            ->with('pushSubscriptions')
            ->get()
            ->keyBy('id');
        if ($users->isEmpty()) {
            return;
        }

        foreach ($notices as $notice) {
            $user = $users->get((int) $notice['user_id']);
            if ($user instanceof User) {
                $user->notify(new AccountNoticePush((string) $notice['title'], (string) $notice['body']));
            }
        }
    }

    /**
     * Save this browser's subscription for the signed-in user. An endpoint that belonged
     * to someone else on a shared phone moves to this user.
     *
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding?: string|null}  $subscription
     */
    public function subscribe(Request $request, User $user, array $subscription): void
    {
        $user->updatePushSubscription(
            $subscription['endpoint'],
            $subscription['keys']['p256dh'],
            $subscription['keys']['auth'],
            $subscription['content_encoding'] ?? 'aes128gcm',
        );
        $request->session()->put(self::SessionEndpoint, $subscription['endpoint']);
    }

    public function unsubscribe(Request $request, User $user, string $endpoint): void
    {
        $user->deletePushSubscription($endpoint);
        $request->session()->forget(self::SessionEndpoint);
    }

    /**
     * Signing out stops pushes to this browser, so the next person on a shared phone
     * does not see the previous user's notices.
     */
    public function forgetOnLogout(?User $user, ?string $endpoint): void
    {
        if (! $user instanceof User || ! is_string($endpoint) || $endpoint === '') {
            return;
        }
        PushSubscription::query()
            ->where('endpoint', $endpoint)
            ->where('subscribable_type', $user->getMorphClass())
            ->where('subscribable_id', $user->getKey())
            ->delete();
    }
}
