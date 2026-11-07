<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Security\DataCleanup;

/**
 * Owner only: old login codes, SMS records and closed enquiries the owner may delete by hand.
 */
final class DataCleanupSection implements WorkspaceSection
{
    /** Days used when the owner has not picked an age yet. */
    public const DefaultOlderThanDays = 30;

    public function __construct(private DataCleanup $cleanup) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'data-cleanup') {
            return [];
        }
        abort_unless($context->portal === 'sadmin' && DataCleanup::canUse($context->user), 403);
        $filters = $context->request->validate([
            'kind' => 'nullable|in:'.implode(',', DataCleanup::Kinds),
            'older_than_days' => 'nullable|integer|min:0|max:3650',
        ]);
        $kind = $filters['kind'] ?? null;
        $days = (int) ($filters['older_than_days'] ?? self::DefaultOlderThanDays);

        return ['data_cleanup' => [
            'rows' => $this->cleanup->listing($kind, $days),
            'counts' => $this->cleanup->counts($days),
            'kinds' => DataCleanup::Kinds,
            'filters' => ['kind' => $kind, 'older_than_days' => $days],
            'max_per_delete' => DataCleanup::MaxPerDelete,
        ]];
    }
}
