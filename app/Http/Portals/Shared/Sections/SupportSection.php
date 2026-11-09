<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Support\ChatRouting;
use App\Domain\Support\PartnerChat;
use App\Domain\Support\PartnerSupportHistory;
use App\Domain\Support\SupportTickets;
use App\Domain\Support\UserManual;
use App\Models\User;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
            'chat_no_branch' => app(ChatRouting::class)->visibleBranchIds($context->user) === [],
            // Only owners choose who answers chat.
            'chat_routing' => $context->user->owner() ? app(ChatRouting::class)->ownerPanel() : null,
        ];

        return $props;
    }

    /**
     * The support desk: one searchable list of every ticket, with its replies for the drawer,
     * and how many tickets are in each state.
     *
     * @return array<string, mixed>
     */
    private function supportDesk(SectionContext $context): array
    {
        $request = $context->request;
        abort_unless($context->isStaff || $context->user->owner(), 403);

        $now = CarbonImmutable::now('UTC');
        $cutoff = $now->subHours(SupportTickets::overdueHours());
        $waitingSince = self::waitingSinceSql();
        $statusFilter = new MoneyTableFilter('status', (string) __('portal.col_status'), null, [
            ...array_map(fn (string $status): array => ['value' => $status, 'label' => (string) __('portal.status_'.$status)], SupportTickets::Statuses),
            ['value' => SupportTickets::OverdueFilter, 'label' => (string) __('portal.support_filter_overdue')],
        ]);
        $status = MoneyTable::chosenFilters($request, [$statusFilter])['status'] ?? null;

        $query = DB::table('support_tickets')
            ->join('users', 'users.id', '=', 'support_tickets.user_id')
            ->when($status !== null && $status !== SupportTickets::OverdueFilter, fn (Builder $tickets) => $tickets->where('support_tickets.status', $status))
            ->when($status === SupportTickets::OverdueFilter, fn (Builder $tickets) => $tickets->whereRaw("({$waitingSince}) < ?", [$cutoff->toDateTimeString()]))
            ->select(['support_tickets.*', 'users.name as user_name', 'users.dealer_code', 'users.role as user_role'])
            ->selectRaw("({$waitingSince}) AS waiting_since");

        $table = MoneyTable::make($request, $query, new MoneyTableSpec(
            dateColumn: 'support_tickets.created_at',
            search: ['users.name', 'users.dealer_code', 'support_tickets.subject', 'support_tickets.body'],
            filters: [
                $statusFilter,
                new MoneyTableFilter('category', (string) __('portal.support_category'), 'support_tickets.category', array_map(
                    fn (string $category): array => ['value' => $category, 'label' => (string) __('portal.support_cat_'.$category)],
                    SupportTickets::Categories,
                )),
            ],
            sorts: ['created_at' => 'support_tickets.created_at', 'name' => 'users.name', 'subject' => 'support_tickets.subject', 'status' => 'support_tickets.status', 'waiting' => "({$waitingSince})"],
            defaultSort: 'created_at',
            tieBreaker: 'support_tickets.id',
            defaultPeriod: null,
        ));

        $replies = $this->repliesFor(array_map(fn (object $ticket): string => (string) $ticket->id, $table['rows']->items()));
        $table['rows']->through(function (object $ticket) use ($now, $cutoff, $replies): array {
            $since = $ticket->waiting_since ? CarbonImmutable::parse((string) $ticket->waiting_since, 'UTC') : null;

            return [
                'id' => (string) $ticket->id,
                'category' => (string) $ticket->category,
                'subject' => (string) $ticket->subject,
                'body' => (string) $ticket->body,
                'status' => (string) $ticket->status,
                'attachment_name' => $ticket->attachment_name,
                'has_attachment' => filled($ticket->attachment_path),
                'created_at' => (string) $ticket->created_at,
                'user_name' => $ticket->user_name,
                'dealer_code' => $ticket->dealer_code,
                'user_role' => $ticket->user_role,
                'waiting_hours' => $since ? (int) floor($since->diffInMinutes($now) / 60) : null,
                'overdue' => $since !== null && $since->lessThan($cutoff),
                'replies' => $replies[(string) $ticket->id] ?? [],
            ];
        });

        return [
            'support_admin' => [
                'table' => $table,
                'counts' => DB::table('support_tickets')->groupBy('status')->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status')->map(fn ($total): int => (int) $total)->all(),
                'overdue_count' => app(SupportTickets::class)->overdueCount(),
                'overdue_hours' => SupportTickets::overdueHours(),
            ],
        ];
    }

    /**
     * When the partner started waiting for the office: when the ticket was opened if no staff
     * member has replied, otherwise the partner's first message after the last staff reply.
     * NULL when the ticket is resolved or the office has the last word. The same rule as
     * SupportTickets::overdueCount(), so the list and the count of late tickets agree.
     */
    private static function waitingSinceSql(): string
    {
        $lastStaffReply = '(SELECT MAX(staff_reply.created_at) FROM support_ticket_replies staff_reply WHERE staff_reply.support_ticket_id = support_tickets.id AND staff_reply.is_staff = true)';

        return "CASE WHEN support_tickets.status = 'resolved' THEN NULL"
            ." WHEN {$lastStaffReply} IS NULL THEN support_tickets.created_at"
            .' ELSE (SELECT MIN(partner_reply.created_at) FROM support_ticket_replies partner_reply WHERE partner_reply.support_ticket_id = support_tickets.id AND partner_reply.is_staff = false'
            ." AND partner_reply.created_at > {$lastStaffReply}) END";
    }

    /**
     * Each listed ticket's replies, oldest first, in one query.
     *
     * @param  list<string>  $ticketIds
     * @return array<string, list<array{id: mixed, body: mixed, is_staff: bool, author_name: mixed, created_at: string}>>
     */
    private function repliesFor(array $ticketIds): array
    {
        $replies = [];
        $rows = DB::table('support_ticket_replies')
            ->join('users', 'users.id', '=', 'support_ticket_replies.user_id')
            ->whereIn('support_ticket_id', $ticketIds)
            ->orderBy('support_ticket_replies.created_at')
            ->orderBy('support_ticket_replies.id')
            ->get(['support_ticket_replies.id', 'support_ticket_replies.support_ticket_id', 'support_ticket_replies.body', 'support_ticket_replies.is_staff', 'support_ticket_replies.created_at', 'users.name as author_name']);

        foreach ($rows as $reply) {
            $replies[(string) $reply->support_ticket_id][] = [
                'id' => $reply->id,
                'body' => $reply->body,
                'is_staff' => (bool) $reply->is_staff,
                'author_name' => $reply->author_name,
                'created_at' => (string) $reply->created_at,
            ];
        }

        return $replies;
    }
}
