<?php

namespace App\Http\Portals\Shared\Sections;

use App\Support\OfficeHubs;

/**
 * Adds the tab strip to office pages that sit in a menu row, and the links to the Settings page.
 */
final class OfficeHubSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if (! $context->isStaff) {
            return [];
        }
        $onOfficeHost = in_array($context->portal, ['manage', 'sadmin'], true);
        if (in_array($context->section, OfficeHubs::CommissionWorkspaceSections, true)) {
            return ['hub' => OfficeHubs::commission($context->user, $onOfficeHost, $context->section)];
        }
        if ($context->section === OfficeHubs::SettingsSection) {
            return ['settings_groups' => OfficeHubs::settingsGroups($context->user, $context->catalogPortal, $onOfficeHost)];
        }
        $hub = OfficeHubs::forSection($context->user, $context->catalogPortal, $onOfficeHost, $context->section);

        return $hub === null ? [] : ['hub' => $hub];
    }
}
