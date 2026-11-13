<?php

namespace App\Http\Controllers;

use App\Support\ReasonFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The owners' switches for the reason and note boxes. EnforceStaffActions lets owners
 * through and this controller checks again.
 */
class ReasonFieldsController extends Controller
{
    public function update(Request $request, ReasonFields $settings): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $data = $request->validate([
            'version' => 'required|integer|min:0',
            'modes' => 'required|array',
            'modes.*' => ['required', Rule::in(ReasonFields::Modes)],
        ]);
        if (array_diff(array_keys($data['modes']), array_keys(ReasonFields::Places)) !== []) {
            throw ValidationException::withMessages(['modes' => __('reason_fields.err_unknown_place')]);
        }
        $settings->save($owner, $data['modes'], (int) $data['version']);

        return back()->with('success', __('reason_fields.flash_saved'));
    }
}
