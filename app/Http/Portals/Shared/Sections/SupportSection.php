<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Support\ChatRouting;
use App\Domain\Support\PartnerChat;
use App\Domain\Support\PartnerSupportHistory;
use App\Domain\Support\SupportTickets;
use App\Domain\Support\UserManual;
use App\Models\User;

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
            'messages' => ['chat' => app(PartnerChat::class)->partnerThread($context->user)],
            'partner-messages' => $this->officeChat($context),
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
        $history = app(PartnerSupportHistory::class);
        $props['support_tickets'] = $history->withOfficeReplies(app(SupportTickets::class)->forUser($user));
        $props['earlier_support_requests'] = $history->earlierRequests($user);
        $props['support_categories'] = SupportTickets::Categories;

        return $props;
    }

    /**
     * The office chat inbox, and the chosen partner's thread when ?with=<partner id> is given.
     *
     * @return array<string, mixed>
     */
    private function officeChat(SectionContext $context): array
    {
        $request = $context->request;
        abort_unless(PartnerChat::canAnswer($context->user), 403);
        $chat = app(PartnerChat::class);
        // Open the chosen chat first, so it is already marked read when the inbox list is built.
        $thread = null;
        if ($request->filled('with')) {
            $partnerId = (int) $request->validate(['with' => 'required|integer'])['with'];
            $partner = User::query()->whereKey($partnerId)->whereIn('role', ['dealer', 'sub_dealer'])->firstOrFail();
            $thread = $chat->officeThread($context->user, $partner);
        }
        $props = [
            'chat_inbox' => $chat->inbox(
                $context->user,
                $request->filled('q') ? (string) $request->input('q') : null,
                $request->input('status') === 'unread',
            ),
            'chat_thread' => $thread,
            // Only owners choose who answers chat.
            'chat_routing' => $context->user->owner() ? app(ChatRouting::class)->ownerPanel() : null,
        ];

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
