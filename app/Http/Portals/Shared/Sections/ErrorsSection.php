<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Diagnostics\ErrorTracker;
use App\Domain\Diagnostics\ErrorTrackingSettings;

/**
 * Owner only: the site's own error log (problems found while it ran), a detail view for one
 * problem, and the switch and retention days of the log.
 */
final class ErrorsSection implements WorkspaceSection
{
    public function __construct(private ErrorTracker $tracker, private ErrorTrackingSettings $settings) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'errors') {
            return [];
        }
        abort_unless($context->portal === 'sadmin' && $context->user->owner(), 403);
        $filters = $context->request->validate([
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:'.implode(',', ErrorTracker::Statuses),
            'sort' => 'nullable|in:'.implode(',', ErrorTracker::Sorts),
            'error' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1',
        ]);
        $status = $filters['status'] ?? 'open';
        $sort = $filters['sort'] ?? 'last_seen';

        return ['errors' => [
            'rows' => $this->tracker->listing($filters['q'] ?? null, $status, $sort, (int) ($filters['page'] ?? 1)),
            'counts' => $this->tracker->counts(),
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $status, 'sort' => $sort],
            'detail' => isset($filters['error']) ? $this->tracker->find((int) $filters['error']) : null,
            'settings' => $this->settings->current(),
            'limits' => ['min' => ErrorTrackingSettings::MinRetentionDays, 'max' => ErrorTrackingSettings::MaxRetentionDays],
        ]];
    }
}
