<?php

namespace App\Jobs;

use App\Domain\Content\PartnerEventNotifications;
use App\Models\PartnerEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the "meeting published / cancelled" SMS to one batch of partners in the
 * background, so publishing an event for every partner does not hold up the office
 * screen or the database. Queued only after the event change is saved.
 */
class SendPartnerEventSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(public int $eventId, public array $userIds, public string $smsEvent, public string $smsKey)
    {
        $this->afterCommit();
    }

    public function handle(PartnerEventNotifications $notifications): void
    {
        $event = PartnerEvent::query()->find($this->eventId);
        if (! $event instanceof PartnerEvent) {
            return;
        }
        // A "published" text is not sent if the meeting was cancelled before the queue got to it.
        if ($this->smsEvent === 'event.published' && $event->status !== 'published') {
            return;
        }
        $notifications->sendSms($event, $this->userIds, $this->smsEvent, $this->smsKey);
    }
}
