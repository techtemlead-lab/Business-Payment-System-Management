<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Orders\RepeatOrderOptions;

/**
 * Partner tools that sit on top of the existing portal pages.
 */
final class DealerToolsSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        $isPartner = in_array($context->user->role, ['dealer', 'sub_dealer'], true) && ! $context->usingStaffWorkspace;
        $props = [];
        if ($isPartner && $context->section === 'purchase') {
            $props['repeat_orders'] = app(RepeatOrderOptions::class)->for($context->user);
        }

        return $props;
    }
}
