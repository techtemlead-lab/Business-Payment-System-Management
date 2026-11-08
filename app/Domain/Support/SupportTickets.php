<?php

namespace App\Domain\Support;

use App\Models\User;
use App\Support\Audit;
use App\Support\DashboardManagement;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupportTickets
{
    /** @var list<string> */
    public const Categories = ['withdrawal', 'commission', 'order_invoice', 'technical', 'other', 'complaint', 'kyc_correction'];

    /** @var list<string> */
    public const Statuses = ['pending', 'in_progress', 'resolved'];

    /** The support desk filter value (?status=overdue) for tickets waiting too long. */
    public const OverdueFilter = 'overdue';

    /** Hours a ticket may wait for the office before it is marked overdue, when the owner has not changed it. */
    public const DefaultOverdueHours = 24;

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, int $limit = 40): array
    {
        return DB::table('support_tickets')
            ->where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->mapTicket($row))
            ->all();
    }

    /**
     * Every ticket for the support desk, newest first, with how long each open ticket has
     * waited for the office and whether that is past the owner's limit.
     *
     * @return array{tickets: list<array<string, mixed>>, filters: array{status: ?string}, overdue_count: int, overdue_hours: int}
     */
    public function adminIndex(?string $status = null, int $limit = 50): array
    {
        $hours = self::overdueHours();
        $now = CarbonImmutable::now('UTC');
        $cutoff = $now->subHours($hours);
        $rows = DB::table('support_tickets')
            ->join('users', 'users.id', '=', 'support_tickets.user_id')
            ->when($status && in_array($status, self::Statuses, true), fn ($q) => $q->where('support_tickets.status', $status))
            ->when($status === self::OverdueFilter, fn ($q) => $q->whereRaw('('.self::waitingSinceSql().') < ?', [$cutoff->toDateTimeString()]))
            ->latest('support_tickets.created_at')
            ->limit($limit)
            ->select([
                'support_tickets.*',
                'users.name as user_name',
                'users.dealer_code',
                'users.role as user_role',
            ])
            ->selectRaw('('.self::waitingSinceSql().') AS waiting_since')
            ->get()
            ->map(function ($row) use ($now, $cutoff): array {
                $waitingSince = $row->waiting_since ? CarbonImmutable::parse((string) $row->waiting_since, 'UTC') : null;

                return [
                    ...$this->mapTicket($row),
                    'user_name' => $row->user_name,
                    'dealer_code' => $row->dealer_code,
                    'user_role' => $row->user_role,
                    'waiting_since' => $waitingSince?->toIso8601String(),
                    'waiting_hours' => $waitingSince ? (int) floor($waitingSince->diffInMinutes($now) / 60) : null,
                    'overdue' => $waitingSince !== null && $waitingSince->lessThan($cutoff),
                ];
            })
            ->all();

        return ['tickets' => $rows, 'filters' => ['status' => $status], 'overdue_count' => $this->overdueCount(), 'overdue_hours' => $hours];
    }

    /**
     * Hours a ticket may wait for the office before it counts as overdue (owner setting, default 24).
     */
    public static function overdueHours(): int
    {
        $hours = (int) (DashboardManagement::get()['support_overdue_hours'] ?? self::DefaultOverdueHours);

        return $hours > 0 ? $hours : self::DefaultOverdueHours;
    }

    /**
     * How many open tickets have waited for the office longer than the owner's limit.
     */
    public function overdueCount(): int
    {
        $cutoff = CarbonImmutable::now('UTC')->subHours(self::overdueHours())->toDateTimeString();

        return DB::table('support_tickets')->whereRaw('('.self::waitingSinceSql().') < ?', [$cutoff])->count();
    }

    /**
     * SQL for when the partner started waiting for the office: when the ticket was opened if no
     * staff member has replied yet, otherwise the partner's first message after the last staff
     * reply. NULL when the ticket is resolved or the office has the last word.
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
     * @return array<string, mixed>
     */
    public function create(User $user, array $input, ?UploadedFile $attachment = null): array
    {
        $category = (string) ($input['category'] ?? '');
        if (! in_array($category, self::Categories, true)) {
            throw ValidationException::withMessages(['category' => __('portal.err_support_category')]);
        }
        $subject = trim((string) ($input['subject'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > 180) {
            throw ValidationException::withMessages(['subject' => __('portal.err_support_subject')]);
        }
        if ($body === '' || mb_strlen($body) > 5000) {
            throw ValidationException::withMessages(['body' => __('portal.err_support_body')]);
        }

        $id = (string) Str::uuid();
        $path = null;
        $name = null;
        if ($attachment) {
            $path = $attachment->storeAs('support-tickets/'.$user->id, $id.'.'.($attachment->guessExtension() ?: 'bin'), 'local');
            $name = Str::limit((string) $attachment->getClientOriginalName(), 180, '');
        }

        DB::table('support_tickets')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'category' => $category,
            'subject' => $subject,
            'body' => $body,
            'status' => 'pending',
            'attachment_path' => $path,
            'attachment_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Audit::record($user->id, 'support.ticket_created', $id, ['category' => $category]);

        return $this->show($id, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id, ?User $viewer = null): array
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404);
        if ($viewer && ! $viewer->staff() && ! $viewer->owner() && (int) $ticket->user_id !== (int) $viewer->id) {
            abort(404);
        }
        $replies = DB::table('support_ticket_replies')
            ->join('users', 'users.id', '=', 'support_ticket_replies.user_id')
            ->where('support_ticket_id', $id)
            ->orderBy('support_ticket_replies.created_at')
            ->get([
                'support_ticket_replies.id',
                'support_ticket_replies.body',
                'support_ticket_replies.is_staff',
                'support_ticket_replies.created_at',
                'users.name as author_name',
            ])
            ->map(fn ($row) => [
                'id' => $row->id,
                'body' => $row->body,
                'is_staff' => (bool) $row->is_staff,
                'author_name' => $row->author_name,
                'created_at' => (string) $row->created_at,
            ])
            ->all();

        return [...$this->mapTicket($ticket), 'replies' => $replies];
    }

    /**
     * @return array<string, mixed>
     */
    public function reply(User $actor, string $id, string $body, ?string $status = null): array
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404);
        $isStaff = $actor->staff() || $actor->owner();
        if (! $isStaff && (int) $ticket->user_id !== (int) $actor->id) {
            abort(404);
        }
        $text = trim($body);
        if ($text === '' || mb_strlen($text) > 5000) {
            throw ValidationException::withMessages(['body' => __('portal.err_support_body')]);
        }

        DB::table('support_ticket_replies')->insert([
            'support_ticket_id' => $id,
            'user_id' => $actor->id,
            'body' => $text,
            'is_staff' => $isStaff,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nextStatus = $ticket->status;
        if ($isStaff && $status && in_array($status, self::Statuses, true)) {
            $nextStatus = $status;
        } elseif ($isStaff && $ticket->status === 'pending') {
            $nextStatus = 'in_progress';
        }

        DB::table('support_tickets')->where('id', $id)->update([
            'status' => $nextStatus,
            'assigned_to' => $isStaff ? $actor->id : $ticket->assigned_to,
            'resolved_at' => $nextStatus === 'resolved' ? now() : null,
            'updated_at' => now(),
        ]);

        Audit::record($actor->id, 'support.ticket_replied', $id, ['status' => $nextStatus]);

        return $this->show($id, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTicket(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'category' => (string) $row->category,
            'subject' => (string) $row->subject,
            'body' => (string) $row->body,
            'status' => (string) $row->status,
            'attachment_name' => $row->attachment_name,
            'has_attachment' => filled($row->attachment_path),
            'created_at' => (string) $row->created_at,
            'resolved_at' => $row->resolved_at ? (string) $row->resolved_at : null,
        ];
    }
}
