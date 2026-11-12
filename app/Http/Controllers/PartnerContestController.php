<?php

namespace App\Http\Controllers;

use App\Domain\Orders\PartnerContests;
use App\Models\PartnerContest;
use App\Support\ReasonFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartnerContestController extends Controller
{
    public function store(Request $request, PartnerContests $contests): RedirectResponse
    {
        abort_unless(PartnerContests::canManage($request->user()), 403);
        $data = $request->validate($this->rules());
        $contests->add($request->user(), $data);

        return back()->with('success', __('dealer_tools.contest_saved'));
    }

    public function update(Request $request, PartnerContest $partnerContest, PartnerContests $contests): RedirectResponse
    {
        abort_unless(PartnerContests::canManage($request->user()), 403);
        $data = $request->validate([...$this->rules(), 'reason' => ReasonFields::rule('partner.contest_edit', 1000, 5)]);
        $contests->update($request->user(), $partnerContest, [...$data, 'reason' => ReasonFields::text($data['reason'] ?? null)]);

        return back()->with('success', __('dealer_tools.contest_updated'));
    }

    public function destroy(Request $request, PartnerContest $partnerContest, PartnerContests $contests): RedirectResponse
    {
        $contests->remove($request->user(), $partnerContest);

        return back()->with('success', __('dealer_tools.contest_removed'));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'title' => 'required|string|max:150',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'metric' => ['required', Rule::in(PartnerContest::Metrics)],
            'prize_text' => 'nullable|string|max:500',
        ];
    }
}
