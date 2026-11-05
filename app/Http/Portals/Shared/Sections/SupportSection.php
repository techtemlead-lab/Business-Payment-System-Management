<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Support\SupportTickets;
use App\Domain\Support\UserManual;

/**
 * Help: the user manual, support tickets and the support desk.
 */
final class SupportSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'help' => $this->help($context),
            'support-admin' => $this->supportDesk($context),
            default => [],
        };
    }

    /**
     * The user manual, searched when asked, with the user's support tickets.
     *
     * @return array<string, mixed>
     */
    private function help(SectionContext $context): array
    {
        $request = $context->request;
        $user = $context->user;
        $props = [];
        $manualQuery = $request->filled('q') ? (string) $request->input('q') : null;
        $props['manual_query'] = $manualQuery;
        $props['manual_sections'] = app(UserManual::class)->chaptersFor($user, $manualQuery);
        $props['support_tickets'] = app(SupportTickets::class)->forUser($user);
        $props['support_categories'] = SupportTickets::Categories;

        return $props;
    }

    /**
     * Every support ticket for the support desk.
     *
     * @return array<string, mixed>
     */
    private function supportDesk(SectionContext $context): array
    {
        $request = $context->request;
        $user = $context->user;
        abort_unless($context->isStaff || $user->owner(), 403);

        return [
            'support_admin' => app(SupportTickets::class)->adminIndex(
                $request->filled('status') ? (string) $request->input('status') : null
            ),
        ];
    }
}
