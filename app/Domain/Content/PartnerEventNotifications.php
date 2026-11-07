<?php

namespace App\Domain\Content;

use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\WebPushNotices;
use App\Jobs\SendPartnerEventSms;
use App\Models\PartnerEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells the partners in an event's audience when it is published or cancelled:
 * always with a portal notice (account_notices, like circulars), and by SMS
 * only when the event's "send SMS" box was ticked (queued, see SendPartnerEventSms).
 * Posts no financial entries.
 */
final class PartnerEventNotifications
{
    public function __construct(private PartnerEventSms $sms) {}

    /** @return int Number of partners who got a notice. */
    public function published(PartnerEvent $event): int
    {
        return $this->fanOut($event, 'portal.events_notice_published_title', 'portal.events_notice_published_body', 'event.published', 'portal.events_sms_published');
    }

    /** @return int Number of partners who got a notice. */
    public function cancelled(PartnerEvent $event): int
    {
        $bodyKey = filled($event->cancel_reason) ? 'portal.events_notice_cancelled_body_reason' : 'portal.events_notice_cancelled_body';

        return $this->fanOut($event, 'portal.events_notice_cancelled_title', $bodyKey, 'event.cancelled', 'portal.events_sms_cancelled');
    }

    /**
     * Active dealers and sub-dealers in the event's audience.
     *
     * @return Builder<User>
     */
    public function audience(PartnerEvent $event): Builder
    {
        return User::query()
            ->where('status', 'active')
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->when($event->audience === 'divisions', fn (Builder $query) => $query->whereIn('division', $event->divisions ?? []));
    }

    private function fanOut(PartnerEvent $event, string $titleKey, string $bodyKey, string $smsEvent, string $smsKey): int
    {
        $count = 0;
        $this->audience($event)
            ->select(['id', 'locale', 'phone'])
            ->chunkById(500, function (Collection $users) use ($event, $titleKey, $bodyKey, $smsEvent, $smsKey, &$count): void {
                $timestamp = now();
                $rows = $users->map(function (User $user) use ($event, $titleKey, $bodyKey, $timestamp): array {
                    $locale = $user->preferredLocale();
                    $replace = $this->replacements($event, $locale);

                    return [
                        'user_id' => $user->id,
                        'title' => __($titleKey, $replace, $locale),
                        'body' => __($bodyKey, $replace, $locale),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                })->all();
                DB::table('account_notices')->insert($rows);
                app(WebPushNotices::class)->send($rows);
                $count += $users->count();

                if ($event->send_sms) {
                    // Texts go out in the background after the change is saved (SendPartnerEventSms).
                    SendPartnerEventSms::dispatch($event->id, $users->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(), $smsEvent, $smsKey);
                }
            });

        return $count;
    }

    /**
     * Send the event SMS to these partners (still active dealers or sub-dealers only).
     * Called by the SendPartnerEventSms job.
     *
     * @param  list<int>  $userIds
     */
    public function sendSms(PartnerEvent $event, array $userIds, string $smsEvent, string $smsKey): void
    {
        User::query()
            ->whereIn('id', $userIds)
            ->where('status', 'active')
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->select(['id', 'locale', 'phone'])
            ->orderBy('id')
            ->each(function (User $user) use ($event, $smsEvent, $smsKey): void {
                $locale = $user->preferredLocale();
                $this->sms->send($user, $smsEvent, __($smsKey, $this->replacements($event, $locale), $locale));
            });
    }

    /** @return array<string, string> */
    private function replacements(PartnerEvent $event, string $locale): array
    {
        $start = $event->startsInDhaka()->locale($locale);
        $where = $event->place ?: ($event->online_url ? __('portal.events_online', [], $locale) : '');

        return [
            'title' => $locale === 'bn' ? $event->title_bn : $event->title_en,
            'date' => $start->translatedFormat('j F Y'),
            'time' => $start->format('g:i A'),
            'place' => $where,
            'reason' => (string) $event->cancel_reason,
        ];
    }
}
