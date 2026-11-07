<?php

namespace App\Domain\Support;

use App\Models\PartnerConversation;
use App\Models\PartnerMessage;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Chat between a dealer or sub-dealer and the company office.
 *
 * Every partner has exactly one conversation. The partner always writes to "the office".
 * Owners see and answer every chat. Staff the owner nominated answer the chats of partners
 * in their own branches, or every chat when nominated company-wide (see ChatRouting).
 * Partners can never message each other.
 */
final class PartnerChat
{
    /** Photos only: a screenshot of a problem, a product photo. Up to 5 MB. */
    public const AttachmentRules = 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120';

    public const MaxBody = 2000;

    /** Messages shown in one thread view (newest last). */
    public const ThreadLimit = 200;

    public static function isPartner(User $user): bool
    {
        return in_array($user->role, ['dealer', 'sub_dealer'], true) && ! $user->usingStaffWorkspace();
    }

    /** Owners always; other staff only when the owner nominated them to answer chat. */
    public static function canAnswer(User $user): bool
    {
        return app(ChatRouting::class)->canAnswer($user);
    }

    /** The viewer may answer chat and this partner's chat is routed to them. */
    public static function canSeePartner(User $viewer, User $partner): bool
    {
        return app(ChatRouting::class)->canSeePartner($viewer, $partner);
    }

    public function conversationFor(User $partner): PartnerConversation
    {
        return PartnerConversation::query()->firstOrCreate(['partner_id' => $partner->id]);
    }

    public function sendFromPartner(User $partner, ?string $body, ?UploadedFile $photo): PartnerMessage
    {
        abort_unless(self::isPartner($partner), 403);

        return $this->send($this->conversationFor($partner), $partner, PartnerMessage::SidePartner, $body, $photo);
    }

    public function replyFromOffice(User $staff, User $partner, ?string $body, ?UploadedFile $photo): PartnerMessage
    {
        abort_unless(self::canAnswer($staff), 403);
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 404);
        abort_unless(self::canSeePartner($staff, $partner), 403);

        return $this->send($this->conversationFor($partner), $staff, PartnerMessage::SideOffice, $body, $photo);
    }

    /**
     * The partner's own thread. Opening it marks office replies as read.
     *
     * @return array{conversation_id: int|null, messages: list<array<string, mixed>>}
     */
    public function partnerThread(User $partner): array
    {
        $conversation = PartnerConversation::query()->where('partner_id', $partner->id)->first();
        if (! $conversation) {
            return ['conversation_id' => null, 'messages' => []];
        }
        if ($conversation->partner_unread > 0) {
            $conversation->forceFill(['partner_unread' => 0])->save();
        }

        return ['conversation_id' => $conversation->id, 'messages' => $this->messages($conversation, false)];
    }

    /**
     * Office inbox: one row per partner the viewer may answer, newest message first, unread first.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function inbox(User $viewer, ?string $search, bool $unreadOnly): LengthAwarePaginator
    {
        $query = PartnerConversation::query()
            ->join('users', 'users.id', '=', 'partner_conversations.partner_id')
            ->tap(fn ($rows) => app(ChatRouting::class)->constrainToViewer($rows, $viewer))
            ->whereNotNull('partner_conversations.last_message_at')
            ->when($unreadOnly, fn ($rows) => $rows->where('partner_conversations.office_unread', '>', 0))
            ->when(filled($search), function ($rows) use ($search): void {
                $term = '%'.addcslashes((string) $search, '%_\\').'%';
                $rows->where(fn ($match) => $match->where('users.name', 'ilike', $term)
                    ->orWhere('users.dealer_code', 'ilike', $term)
                    ->orWhere('users.phone', 'ilike', $term));
            })
            ->orderByRaw('(partner_conversations.office_unread > 0) DESC')
            ->orderByDesc('partner_conversations.last_message_at')
            ->select([
                'partner_conversations.id',
                'partner_conversations.partner_id',
                'partner_conversations.last_message_at',
                'partner_conversations.last_message_preview',
                'partner_conversations.last_sender_side',
                'partner_conversations.office_unread',
                'users.name',
                'users.dealer_code',
                'users.role',
                'users.phone',
                'users.district',
                'users.division',
            ]);

        return $query->paginate(30)->withQueryString()->through(fn ($row): array => [
            'conversation_id' => (int) $row->id,
            'partner_id' => (int) $row->partner_id,
            'name' => (string) $row->name,
            'dealer_code' => $row->dealer_code,
            'role' => (string) $row->role,
            'phone' => $row->phone,
            'place' => $row->district ?: $row->division,
            'last_message_at' => optional($row->last_message_at)?->toIso8601String(),
            'preview' => $row->last_message_preview,
            'last_from_office' => $row->last_sender_side === PartnerMessage::SideOffice,
            'unread' => (int) $row->office_unread,
        ]);
    }

    /**
     * One partner's thread for the office. Opening it marks the partner's messages as read.
     *
     * @return array{partner: array<string, mixed>, messages: list<array<string, mixed>>}
     */
    public function officeThread(User $staff, User $partner): array
    {
        abort_unless(self::canAnswer($staff), 403);
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 404);
        abort_unless(self::canSeePartner($staff, $partner), 403);
        $conversation = PartnerConversation::query()->where('partner_id', $partner->id)->first();
        if ($conversation && $conversation->office_unread > 0) {
            $conversation->forceFill(['office_unread' => 0])->save();
        }

        return [
            'partner' => [
                'id' => $partner->id,
                'name' => $partner->name,
                'dealer_code' => $partner->dealer_code,
                'role' => $partner->role,
                'phone' => $partner->phone,
                'place' => $partner->district ?: $partner->division,
                'status' => $partner->status,
            ],
            'messages' => $conversation ? $this->messages($conversation, true) : [],
        ];
    }

    public function partnerUnread(User $partner): int
    {
        return (int) PartnerConversation::query()->where('partner_id', $partner->id)->value('partner_unread');
    }

    /** Unread partner messages in the chats this viewer may answer. */
    public function officeUnread(User $viewer): int
    {
        return (int) PartnerConversation::query()
            ->join('users', 'users.id', '=', 'partner_conversations.partner_id')
            ->tap(fn ($rows) => app(ChatRouting::class)->constrainToViewer($rows, $viewer))
            ->sum('partner_conversations.office_unread');
    }

    /** A message's photo, for the partner who owns the chat or office staff the chat is routed to. */
    public function attachmentFor(User $viewer, PartnerMessage $message): PartnerMessage
    {
        abort_if($message->attachment_path === null, 404);
        $partnerId = (int) $message->conversation()->value('partner_id');
        if ($viewer->id !== $partnerId) {
            $partner = User::query()->find($partnerId);
            abort_unless($partner && self::canSeePartner($viewer, $partner), 404);
        }

        return $message;
    }

    private function send(PartnerConversation $conversation, User $sender, string $side, ?string $body, ?UploadedFile $photo): PartnerMessage
    {
        $text = trim((string) $body);
        if ($text === '' && ! $photo) {
            throw ValidationException::withMessages(['body' => __('portal.chat_err_empty')]);
        }
        if (mb_strlen($text) > self::MaxBody) {
            throw ValidationException::withMessages(['body' => __('portal.chat_err_too_long', ['max' => (string) self::MaxBody])]);
        }
        $path = $photo ? $photo->storeAs('chat-photos/'.$conversation->id, Str::uuid().'.'.($photo->guessExtension() ?: 'jpg'), 'local') : null;

        return DB::transaction(function () use ($conversation, $sender, $side, $text, $photo, $path): PartnerMessage {
            DB::afterRollBack(function () use ($path): void {
                if ($path) {
                    Storage::disk('local')->delete($path);
                }
            });
            $locked = PartnerConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $message = PartnerMessage::query()->create([
                'conversation_id' => $locked->id,
                'sender_id' => $sender->id,
                'sender_side' => $side,
                'body' => $text !== '' ? $text : null,
                'attachment_path' => $path,
                'attachment_mime' => $photo ? (string) $photo->getMimeType() : null,
            ]);
            $preview = $text !== '' ? Str::limit(preg_replace('/\s+/u', ' ', $text), 150) : '📷';
            $locked->forceFill([
                'last_message_at' => $message->created_at,
                'last_message_preview' => $preview,
                'last_sender_side' => $side,
                'partner_unread' => $side === PartnerMessage::SideOffice ? $locked->partner_unread + 1 : 0,
                // An office reply means the office has read the partner's messages.
                'office_unread' => $side === PartnerMessage::SidePartner ? $locked->office_unread + 1 : 0,
            ])->save();
            if ($side === PartnerMessage::SideOffice) {
                Audit::record($sender->id, 'partner_chat.replied', (string) $locked->partner_id, []);
            }

            return $message;
        });
    }

    /** @return list<array<string, mixed>> */
    private function messages(PartnerConversation $conversation, bool $forOffice): array
    {
        $rows = PartnerMessage::query()
            ->with('sender:id,name,role')
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(self::ThreadLimit)
            ->get()
            ->reverse()
            ->values();

        return $rows->map(fn (PartnerMessage $message): array => [
            'id' => $message->id,
            'side' => $message->sender_side,
            'body' => $message->body,
            'has_photo' => $message->attachment_path !== null,
            'created_at' => optional($message->created_at)?->toIso8601String(),
            // Partners see "Madina office"; the office sees which staff member answered.
            'sender_name' => $forOffice || $message->sender_side === PartnerMessage::SidePartner ? $message->sender?->name : null,
        ])->all();
    }
}
