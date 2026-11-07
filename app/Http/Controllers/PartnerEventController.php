<?php

namespace App\Http\Controllers;

use App\Domain\Content\PartnerEventCalendar;
use App\Models\PartnerEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Office editor for the meetings and training calendar.
 * EnforceStaffActions also checks the staff "content" permission on every change.
 */
class PartnerEventController extends Controller
{
    public function __construct(private PartnerEventCalendar $calendar) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            ...$this->rules(),
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.PartnerEventCalendar::todayInDhaka()],
            'publish' => 'sometimes|boolean',
        ]);
        $event = $this->calendar->save($request->user(), null, $data);

        return back()->with('success', __($event->status === 'published' ? 'portal.events_flash_published' : 'portal.events_flash_saved'));
    }

    public function update(Request $request, PartnerEvent $partnerEvent): RedirectResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate($this->rules());
        $this->calendar->save($request->user(), $partnerEvent, $data);

        return back()->with('success', __('portal.events_flash_saved'));
    }

    public function publish(Request $request, PartnerEvent $partnerEvent): RedirectResponse
    {
        $this->authorizeManager($request);
        $this->calendar->publish($request->user(), $partnerEvent);

        return back()->with('success', __('portal.events_flash_published'));
    }

    public function cancel(Request $request, PartnerEvent $partnerEvent): RedirectResponse
    {
        $this->authorizeManager($request);
        $reason = $request->validate(['reason' => 'nullable|string|max:300'])['reason'] ?? null;
        $this->calendar->cancel($request->user(), $partnerEvent, $reason);

        return back()->with('success', __('portal.events_flash_cancelled'));
    }

    public function destroy(Request $request, PartnerEvent $partnerEvent): RedirectResponse
    {
        $this->authorizeManager($request);
        $this->calendar->delete($request->user(), $partnerEvent);

        return back()->with('success', __('portal.events_flash_deleted'));
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($this->calendar->canManage($request->user()), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'title_bn' => 'required|string|max:200',
            'title_en' => 'required|string|max:200',
            'date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'place' => 'nullable|required_without:online_url|string|max:300',
            'online_url' => 'nullable|string|max:500|url:https',
            'audience' => ['required', Rule::in(PartnerEvent::Audiences)],
            'divisions' => 'nullable|required_if:audience,divisions|array|max:20',
            'divisions.*' => ['integer', Rule::in(array_column($this->calendar->divisionOptions(), 'id'))],
            'notes' => 'nullable|string|max:5000',
            'send_sms' => 'sometimes|boolean',
        ];
    }
}
