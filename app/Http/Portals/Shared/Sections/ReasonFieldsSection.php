<?php

namespace App\Http\Portals\Shared\Sections;

use App\Support\ReasonFields;

/**
 * Owner only: every reason and note box in the portals, each compulsory, optional or off.
 */
final class ReasonFieldsSection implements WorkspaceSection
{
    public function __construct(private ReasonFields $settings) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'reason-fields') {
            return [];
        }
        abort_unless($context->portal === 'sadmin' && $context->user->owner(), 403);

        return ['reason_fields' => $this->settings->page()];
    }
}
