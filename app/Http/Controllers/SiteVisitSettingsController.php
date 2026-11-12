<?php

namespace App\Http\Controllers;

use App\Domain\Audience\SiteVisitSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The owners' switch for counting website visits. EnforceStaffActions lets owners through
 * and this controller checks again.
 */
class SiteVisitSettingsController extends Controller
{
    public function update(Request $request, SiteVisitSettings $settings): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $data = $request->validate([
            'enabled' => 'required|in:0,1',
            'version' => 'required|integer|min:0',
        ]);
        $enabled = $data['enabled'] === '1';
        $settings->save($owner, $enabled, (int) $data['version']);

        return back()->with('success', __($enabled ? 'office_visitors.flash_counting_on' : 'office_visitors.flash_counting_off'));
    }
}
