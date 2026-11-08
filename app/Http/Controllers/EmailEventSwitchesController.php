<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\EmailEventSwitches;
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
            'reason' => FinanceOperationsController::Reason,
        ]);
        $switches->save($request->user(), array_values($data['enabled']), (int) $data['version'], $data['reason']);

        return $request->expectsJson()
            ? response()->json(['data' => []])
            : back()->with('success', __('portal.flash_changes_recorded'));
    }
}
