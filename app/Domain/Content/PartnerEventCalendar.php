<?php

namespace App\Domain\Content;

use App\Domain\Notifications\SmsText;
use App\Models\PartnerEvent;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The meetings and training calendar. Staff enter dates and times in Dhaka
 * time; they are stored in UTC and shown back in Dhaka time. Publishing and
 * cancelling notify the partners in the event's audience.
 */
final class PartnerEventCalendar
{
    /** Past events partners can still look back at. */
    public const PastDays = 90;

    public const PastLimit = 30;

    public const UpcomingLimit = 100;

    public function __construct(private PartnerEventNotifications $notifications) {}

    /** Office staff with the content permission and the owners manage the calendar. */
    public function canManage(User $user): bool
    {
        return $user->status === 'active' && ($user->owner() || $user->allows('content'));
    }

    /** A Dhaka date ("2026-11-10") and clock time ("09:30") as a UTC moment. */
    public static function fromDhaka(string $date, string $time): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, PartnerEvent::Timezone)->utc();
    }

    /** Today's date in Dhaka, e.g. "2026-11-10". */
    public static function todayInDhaka(): string
    {
        return Carbon::now(PartnerEvent::Timezone)->format('Y-m-d');
    }

    /**
     * Divisions staff can pick for an event audience: active, coded division place codes.
     * The value saved is the place code id; the name is only shown.
     *
     * @return list<array{id: int, name: string}>
     */
    public function divisionOptions(): array
    {
        return PlaceCode::query()
            ->where('level', PlaceCode::LevelDivision)
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PlaceCode $place): array => ['id' => (int) $place->id, 'name' => (string) $place->name])
            ->values()
            ->all();
    }

    /**
     * The partner calendar: a today and this-week highlight, upcoming events by
     * month and date, and recent past events.
     *
     * @return array<string, mixed>
     */
    public function partnerView(User $user): array
    {
        $now = now();
        $upcoming = PartnerEvent::query()->visibleTo($user)
            ->where('ends_at', '>=', $now)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(self::UpcomingLimit)
            ->get();
        $past = PartnerEvent::query()->visibleTo($user)
            ->where('ends_at', '<', $now)
            ->where('starts_at', '>=', $now->copy()->subDays(self::PastDays))
            ->orderByDesc('starts_at')
            ->limit(self::PastLimit)
            ->get();

        $today = self::todayInDhaka();
        $weekEnd = Carbon::now(PartnerEvent::Timezone)->addDays(6)->format('Y-m-d');
        $present = fn (PartnerEvent $event): array => $this->present($event, false);

        return [
            'today_date' => $today,
            'timezone' => PartnerEvent::Timezone,
            'today' => $upcoming->filter(fn (PartnerEvent $event): bool => $this->dhakaDate($event) <= $today)->map($present)->values()->all(),
            'this_week' => $upcoming->filter(fn (PartnerEvent $event): bool => $this->dhakaDate($event) > $today && $this->dhakaDate($event) <= $weekEnd)->map($present)->values()->all(),
            'months' => $this->byMonth($upcoming),
            'past' => $past->map($present)->values()->all(),
        ];
    }

    /**
     * Every event for the office: upcoming first, then the most recent past ones.
     *
     * @return list<array<string, mixed>>
     */
    public function staffEvents(): array
    {
        $now = now();
        $upcoming = PartnerEvent::query()->where('ends_at', '>=', $now)->orderBy('starts_at')->orderBy('id')->limit(200)->get();
        $past = PartnerEvent::query()->where('ends_at', '<', $now)->orderByDesc('starts_at')->limit(50)->get();

        return $upcoming->concat($past)->map(fn (PartnerEvent $event): array => $this->present($event, true))->values()->all();
    }

    /**
     * Screen data for one event, with dates and times already in Dhaka time.
     *
     * @return array<string, mixed>
     */
    public function present(PartnerEvent $event, bool $forStaff): array
    {
        $start = $event->startsInDhaka();
        $end = $event->endsInDhaka();
        $now = now();

        return [
            'id' => $event->id,
            'title_bn' => $event->title_bn,
            'title_en' => $event->title_en,
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'place' => $event->place,
            'online_url' => $event->status === 'cancelled' && ! $forStaff ? null : $event->online_url,
            'notes' => $event->notes,
            'status' => $event->status,
            'cancel_reason' => $event->status === 'cancelled' ? $event->cancel_reason : null,
            'is_ongoing' => $event->status !== 'cancelled' && $event->starts_at->lessThanOrEqualTo($now) && $event->ends_at->greaterThanOrEqualTo($now),
            'is_past' => $event->ends_at->lessThan($now),
            ...($forStaff ? [
                'audience' => $event->audience,
                'divisions' => $event->divisions ?? [],
                'division_ids' => array_map('intval', $event->division_place_ids ?? []),
                'send_sms' => $event->send_sms,
                'published_at' => $event->published_at?->toIso8601String(),
                'cancelled_at' => $event->cancelled_at?->toIso8601String(),
            ] : []),
        ];
    }

    /**
     * Create or update an event from validated form data (Dhaka date and times).
     *
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, ?PartnerEvent $event, array $data): PartnerEvent
    {
        if ($event?->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => __('portal.events_err_cancelled_locked')]);
        }
        $startsAt = self::fromDhaka((string) $data['date'], (string) $data['start_time']);
        $endsAt = self::fromDhaka((string) $data['date'], (string) $data['end_time']);
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['end_time' => __('portal.events_err_end_before_start')]);
        }

        $saved = DB::transaction(function () use ($actor, $event, $data, $startsAt, $endsAt): PartnerEvent {
            $event ??= new PartnerEvent(['created_by' => $actor->id, 'status' => 'draft']);
            $audience = (string) $data['audience'];
            // Chosen divisions are place code ids; their names are kept only for display.
            $divisionIds = $audience === 'divisions' ? array_values(array_unique(array_map('intval', (array) $data['divisions']))) : [];
            $divisionNames = PlaceCode::query()->whereIn('id', $divisionIds)->orderBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all();
            $event->fill([
                'title_bn' => trim((string) $data['title_bn']),
                'title_en' => trim((string) $data['title_en']),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'place' => filled($data['place'] ?? null) ? trim((string) $data['place']) : null,
                'online_url' => filled($data['online_url'] ?? null) ? trim((string) $data['online_url']) : null,
                'audience' => $audience,
                'divisions' => $audience === 'divisions' ? $divisionNames : null,
                'division_place_ids' => $audience === 'divisions' ? $divisionIds : null,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'send_sms' => (bool) ($data['send_sms'] ?? false),
                'updated_by' => $actor->id,
            ]);
            $creating = ! $event->exists;
            $event->save();
            Audit::record($actor->id, $creating ? 'event.created' : 'event.updated', (string) $event->id, [
                'title' => $event->title_en,
                'starts_at' => $event->starts_at->toIso8601String(),
                'audience' => $event->audience,
                'divisions' => $event->divisions,
                'division_place_ids' => $event->division_place_ids,
            ]);

            return $event;
        });

        if ((bool) ($data['publish'] ?? false) && $saved->status === 'draft') {
            $this->publish($actor, $saved);
        }

        return $saved->refresh();
    }

    /** @return int Number of partners who got a notice. */
    public function publish(User $actor, PartnerEvent $event): int
    {
        return DB::transaction(function () use ($actor, $event): int {
            $locked = PartnerEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => __('portal.events_err_not_draft')]);
            }
            if ($locked->send_sms && ! SmsText::hasBanglaLetters((string) $locked->title_bn)) {
                throw ValidationException::withMessages(['title_bn' => SmsText::make('portal.sms_err_bangla_required')]);
            }
            $locked->forceFill(['status' => 'published', 'published_at' => now(), 'updated_by' => $actor->id])->save();
            $notified = $this->notifications->published($locked);
            Audit::record($actor->id, 'event.published', (string) $locked->id, ['notified' => $notified, 'sms' => $locked->send_sms]);

            return $notified;
        });
    }

    /** @return int Number of partners who got a notice (none for a draft). */
    public function cancel(User $actor, PartnerEvent $event, ?string $reason): int
    {
        return DB::transaction(function () use ($actor, $event, $reason): int {
            $locked = PartnerEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => __('portal.events_err_already_cancelled')]);
            }
            $wasPublished = $locked->status === 'published';
            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => filled($reason) ? trim((string) $reason) : null,
                'updated_by' => $actor->id,
            ])->save();
            $notified = $wasPublished ? $this->notifications->cancelled($locked) : 0;
            Audit::record($actor->id, 'event.cancelled', (string) $locked->id, ['notified' => $notified, 'reason' => $locked->cancel_reason]);

            return $notified;
        });
    }

    /** Only drafts can be deleted; published events are cancelled instead so partners are told. */
    public function delete(User $actor, PartnerEvent $event): void
    {
        if ($event->status !== 'draft') {
            throw ValidationException::withMessages(['status' => __('portal.events_err_delete_published')]);
        }
        $id = $event->id;
        $title = $event->title_en;
        $event->delete();
        Audit::record($actor->id, 'event.deleted', (string) $id, ['title' => $title]);
    }

    private function dhakaDate(PartnerEvent $event): string
    {
        return $event->startsInDhaka()->format('Y-m-d');
    }

    /**
     * @param  Collection<int, PartnerEvent>  $events
     * @return list<array{month: string, days: list<array{date: string, events: list<array<string, mixed>>}>}>
     */
    private function byMonth(Collection $events): array
    {
        $months = [];
        foreach ($events as $event) {
            $date = $this->dhakaDate($event);
            $month = substr($date, 0, 7);
            $months[$month][$date][] = $this->present($event, false);
        }

        return array_values(array_map(
            fn (string $month, array $days): array => [
                'month' => $month,
                'days' => array_values(array_map(fn (string $date, array $list): array => ['date' => $date, 'events' => $list], array_keys($days), $days)),
            ],
            array_keys($months),
            $months,
        ));
    }
}
