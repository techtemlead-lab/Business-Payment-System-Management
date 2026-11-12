<?php

namespace App\Http\Controllers;

use App\Domain\Audience\SiteVisitCounter;
use App\Domain\Audience\SiteVisitSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * The public website's own script tells this address that a page was opened. It always
 * answers "no content", counted or not, so a visitor never sees an error from it.
 */
class SiteVisitController extends Controller
{
    public function store(Request $request, SiteVisitCounter $counter, SiteVisitSettings $settings): Response
    {
        $browser = (string) $request->userAgent();
        $page = SiteVisitCounter::page($request->input('path'));
        $viewer = $request->user();
        // The company's own people checking the website are not visitors.
        $ownPerson = $viewer !== null && ($viewer->staff() || $viewer->owner());
        if ($page === null || $ownPerson || SiteVisitCounter::looksLikeBot($browser) || ! $settings->countingEnabled()) {
            return response()->noContent();
        }

        $known = $request->cookie(SiteVisitCounter::VisitorCookie);
        $visitorId = SiteVisitCounter::isVisitorId($known) ? $known : SiteVisitCounter::newVisitorId();
        try {
            $counter->record(
                $visitorId,
                $page,
                SiteVisitCounter::source($request->input('referrer'), $request->input('tag'), $request->getHost()),
                SiteVisitCounter::device($browser, $request->input('screen')),
            );
        } catch (Throwable $problem) {
            // Counting must never break a visitor's page; the problem is reported for the developers.
            report($problem);

            return response()->noContent();
        }

        $response = response()->noContent();
        if ($visitorId !== $known) {
            $response->cookie(SiteVisitCounter::VisitorCookie, $visitorId, SiteVisitCounter::VisitorCookieMinutes, '/', null, null, true, false, 'lax');
        }

        return $response;
    }
}
