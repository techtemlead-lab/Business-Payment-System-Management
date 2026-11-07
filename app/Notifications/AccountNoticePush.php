<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A push copy of a portal notice. The in-app notice is written separately; this only goes
 * to users with a saved browser subscription, after the database transaction commits.
 */
class AccountNoticePush extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $title, public string $body)
    {
        $this->afterCommit();
    }

    /**
     * Web push only for users who turned notifications on; nobody else gets a channel.
     *
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User && $notifiable->pushSubscriptions->isNotEmpty()) {
            return [WebPushChannel::class];
        }

        return [];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(Str::limit($this->title, 120))
            ->body(Str::limit($this->body, 240))
            ->icon('/reference/favicon.png?v=8')
            ->badge('/reference/favicon.png?v=8')
            ->lang($notifiable instanceof User ? $notifiable->preferredLocale() : app()->getLocale())
            ->data(['url' => '/notifications'])
            ->options(['TTL' => 86400]);
    }
}
