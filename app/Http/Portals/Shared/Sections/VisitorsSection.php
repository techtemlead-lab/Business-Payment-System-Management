<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Audience\PartnerActivityReport;
use App\Domain\Audience\SiteVisitReport;
use App\Domain\Audience\SiteVisitSettings;

/**
 * Owner only: how many people visit the public website, the switch that turns counting on or off,
 * and (CR-17) which dealers and sub-dealers are online now, with their last IP, device and browser.
 */
final class VisitorsSection implements WorkspaceSection
{
    public function __construct(private SiteVisitReport $report, private SiteVisitSettings $settings, private PartnerActivityReport $partners) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'visitors') {
            return [];
        }
        abort_unless($context->portal === 'sadmin' && $context->user->owner(), 403);
        $request = $context->request;
        $period = $request->query('period');
        $filter = $request->query('status');
        $search = $request->query('q');

        return ['visitors' => [
            ...$this->report->for(
                is_string($period) ? $period : null,
                SectionDates::optionalDate($request->query('from')),
                SectionDates::optionalDate($request->query('to')),
            ),
            'counting' => $this->settings->counting(),
            'partner_activity' => $this->partners->for(
                is_string($filter) ? $filter : null,
                is_string($search) ? $search : null,
                (int) $request->query('page', 1),
            ),
        ]];
    }
}
