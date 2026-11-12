<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\EmailEventSwitches;
use App\Support\ReasonFields;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class EmailEventSwitchesController extends Controller
{
    public function store(Request $request, EmailEventSwitches $switches): Response
    {
        abort_unless($request->user()?->role === 'super_admin', 403);
        $data = $request->validate([
            'enabled' => 'present|array',
            'enabled.*' => ['string', Rule::in(EmailEventSwitches::Events)],
            'version' => 'required|integer|min:0',
            'reason' => ReasonFields::rule('settings.email_events', 1000, 5),
        ]);
        $switches->save($request->user(), array_values($data['enabled']), (int) $data['version'], ReasonFields::text($data['reason'] ?? null));

        return $request->expectsJson()
            ? response()->json(['data' => []])
            : back()->with('success', __('portal.flash_changes_recorded'));
    }
}
