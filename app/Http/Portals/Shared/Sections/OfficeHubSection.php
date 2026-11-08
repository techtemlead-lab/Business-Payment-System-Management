<?php

namespace App\Http\Portals\Shared\Sections;

use App\Support\OfficeHubs;

/**
 * Adds the commission tabs to the commission pages that the portal workspace serves.
 */
final class OfficeHubSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if (! $context->isStaff || ! in_array($context->section, OfficeHubs::CommissionWorkspaceSections, true)) {
            return [];
        }

        return ['hub' => OfficeHubs::commission($context->user, in_array($context->portal, ['manage', 'sadmin'], true), $context->section)];
    }
}
