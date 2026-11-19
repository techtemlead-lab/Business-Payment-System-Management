<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Notifications\MessageTemplates;

/**
 * The editor for every SMS and email text. Who may open it is decided in PortalCatalog::assertSection.
 */
final class MessageTemplatesSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'message-templates') {
            return [];
        }

        return ['message_templates' => MessageTemplates::page()];
    }
}
