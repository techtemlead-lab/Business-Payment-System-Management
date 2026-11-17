<?php

namespace App\Http\Controllers;

use App\Domain\Diagnostics\ErrorTracker;
use App\Domain\Diagnostics\ErrorTrackingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The owners' error log actions: the on/off switch with retention days, and marking a problem
 * as fixed. EnforceStaffActions lets owners through and the domain checks again.
 */
class ErrorTrackingController extends Controller
{
    public function settings(Request $request, ErrorTrackingSettings $settings): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $data = $request->validate([
            'enabled' => 'required|in:0,1',
            'retention_days' => 'required|integer|min:'.ErrorTrackingSettings::MinRetentionDays.'|max:'.ErrorTrackingSettings::MaxRetentionDays,
            'version' => 'required|integer|min:0',
        ]);
        $settings->save($owner, $data['enabled'] === '1', (int) $data['retention_days'], (int) $data['version']);

        return back()->with('success', __('office_errors.flash_settings_saved'));
    }

    public function fixed(Request $request, ErrorTracker $tracker, int $errorReport): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $tracker->markFixed($owner, $errorReport);

        return back()->with('success', __('office_errors.flash_fixed'));
    }
}
