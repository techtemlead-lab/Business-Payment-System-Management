<?php

namespace App\Http\Controllers;

use App\Domain\Security\PrivacyRequests;
use App\Models\PrivacyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A partner's request for a copy of their data or for its deletion, and the owner's answer.
 */
class PrivacyRequestController extends Controller
{
    public function store(Request $request, PrivacyRequests $requests): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(PrivacyRequest::Kinds)]]);
        $requests->submit($request->user(), $data['kind']);

        return back()->with('success', __('prescriber_public.privacy_flash_sent'));
    }

    public function resolve(Request $request, PrivacyRequest $privacyRequest, PrivacyRequests $requests): RedirectResponse
    {
        abort_unless(PrivacyRequests::canResolve($request->user()), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(PrivacyRequest::ResolutionStatuses)],
            'note' => 'nullable|string|max:1000',
        ]);
        $requests->resolve($request->user(), $privacyRequest, $data['status'], $data['note'] ?? null);

        return back()->with('success', __('prescriber_public.privacy_flash_saved'));
    }
}
