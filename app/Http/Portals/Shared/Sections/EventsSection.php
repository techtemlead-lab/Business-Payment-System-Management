<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Content\PartnerEventCalendar;
use App\Models\PartnerEvent;

/**
 * The meetings and training calendar: an editor for office staff, and a
 * month-by-month list of upcoming events for dealers and sub-dealers.
 */
final class EventsSection implements WorkspaceSection
{
    public function __construct(private PartnerEventCalendar $calendar) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'events') {
            return [];
        }
        $user = $context->user;

        if ($context->isStaff) {
            abort_unless($this->calendar->canManage($user), 403);

            return ['events_calendar' => [
                'mode' => 'staff',
                'can_edit' => $user->owner() || $user->canAct('content', 'edit'),
                'can_create' => $user->owner() || $user->canAct('content', 'create'),
                'events' => $this->calendar->staffEvents(),
                'divisions' => $this->calendar->divisionOptions(),
                'today_date' => PartnerEventCalendar::todayInDhaka(),
                'timezone' => PartnerEvent::Timezone,
            ]];
        }

        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);

        return ['events_calendar' => ['mode' => 'partner', ...$this->calendar->partnerView($user)]];
    }
}
