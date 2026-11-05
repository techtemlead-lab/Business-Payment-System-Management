<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerAgreement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Commands for the dealer agreement: the owner publishes versions and turns
 * the "must accept" switch on or off; partners accept the latest version.
 * Owners only sign in to the owner panel, so the owner check also keeps
 * these commands there.
 */
class PartnerAgreementController extends Controller
{
    public function __construct(private PartnerAgreement $agreement) {}

    /** Owner only: publish edited text as a new version. */
    public function publish(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->owner(), 403);
        $data = $request->validate([
            'body_bn' => 'required|string|min:20|max:60000',
            'body_en' => 'required|string|min:20|max:60000',
        ]);

        $version = $this->agreement->publish($request->user(), $data['body_bn'], $data['body_en']);

        return back()->with('success', __('portal.agr_published', ['version' => (string) $version->version]));
    }

    /** Owner only: the "Partners must accept the agreement" switch. */
    public function setting(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->owner(), 403);
        $data = $request->validate([
            'required' => 'required|boolean',
            'version' => 'required|integer|min:0',
        ]);

        $this->agreement->saveSetting($request->user(), (bool) $data['required'], (int) $data['version']);

        return back()->with('success', __('portal.agr_setting_saved'));
    }

    /** Dealer or sub-dealer: "I have read and agree". */
    public function accept(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'version_id' => 'required|integer|min:1',
        ]);

        $this->agreement->accept($request->user(), (int) $data['version_id'], $request->ip(), $request->userAgent());

        return redirect('/')->with('success', __('portal.agr_accepted'));
    }
}
