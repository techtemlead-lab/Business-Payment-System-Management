<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Audience\SiteVisitReport;
use App\Domain\Audience\SiteVisitSettings;

/**
 * Owner only: how many people visit the public website, and the switch that turns counting on or off.
 */
final class VisitorsSection implements WorkspaceSection
{
    public function __construct(private SiteVisitReport $report, private SiteVisitSettings $settings) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'visitors') {
            return [];
        }
        abort_unless($context->portal === 'sadmin' && $context->user->owner(), 403);
        $request = $context->request;
        $period = $request->query('period');

        return ['visitors' => [
            ...$this->report->for(
                is_string($period) ? $period : null,
                SectionDates::optionalDate($request->query('from')),
                SectionDates::optionalDate($request->query('to')),
            ),
            'counting' => $this->settings->counting(),
        ]];
    }
}
