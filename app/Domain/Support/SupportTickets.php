<?php

namespace App\Domain\Support;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupportTickets
{
    /** @var list<string> */
    public const Categories = ['withdrawal', 'commission', 'order_invoice', 'technical', 'other'];

    /** @var list<string> */
    public const Statuses = ['pending', 'in_progress', 'resolved'];

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
     * @return array{tickets: list<array<string, mixed>>, filters: array{status: ?string}}
     */
    public function adminIndex(?string $status = null, int $limit = 50): array
    {
        $rows = DB::table('support_tickets')
            ->join('users', 'users.id', '=', 'support_tickets.user_id')
            ->when($status && in_array($status, self::Statuses, true), fn ($q) => $q->where('support_tickets.status', $status))
            ->latest('support_tickets.created_at')
            ->limit($limit)
            ->get([
                'support_tickets.*',
                'users.name as user_name',
                'users.dealer_code',
                'users.role as user_role',
            ])
            ->map(fn ($row) => [
                ...$this->mapTicket($row),
                'user_name' => $row->user_name,
                'dealer_code' => $row->dealer_code,
                'user_role' => $row->user_role,
            ])
            ->all();

        return ['tickets' => $rows, 'filters' => ['status' => $status]];
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
