<?php

namespace App\Domain\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a partner has already asked the office, for their "My requests" list.
 *
 * Partners used to have two forms. The notices page saved to `enquiries`, which has no
 * link to the account, and the help page saves to `support_tickets`. Old rows stay where
 * they are, so the office desk keeps working, and are shown back to the partner from here.
 */
final class PartnerSupportHistory
{
    /**
     * Topics only the old partner form could send, mapped to their label keys.
     * Older rows may hold the English wording instead of the key.
     *
     * @var array<string, string>
     */
    private const TopicLabelKeys = [
        'support' => 'support_topic_support',
        'complaint' => 'support_topic_complaint',
        'order_dispute' => 'support_topic_order',
        'kyc_correction' => 'support_topic_kyc',
        'payout_question' => 'support_topic_payout',
        'Support' => 'support_topic_support',
        'Complaint' => 'support_topic_complaint',
        'Order dispute' => 'support_topic_order',
        'KYC correction' => 'support_topic_kyc',
        'Payout question' => 'support_topic_payout',
    ];

    /**
     * Adds the office's answers to the partner's tickets. Staff names are left out on purpose.
     *
     * @param  list<array<string, mixed>>  $tickets
     * @return list<array<string, mixed>>
     */
    public function withOfficeReplies(array $tickets): array
    {
        if ($tickets === []) {
            return [];
        }
        $replies = DB::table('support_ticket_replies')
            ->whereIn('support_ticket_id', array_column($tickets, 'id'))
            ->orderBy('created_at')
            ->get(['support_ticket_id', 'body', 'is_staff', 'created_at'])
            ->groupBy('support_ticket_id');

        return array_map(fn (array $ticket): array => [
            ...$ticket,
            'replies' => $replies->get($ticket['id'], collect())
                ->map(fn (object $reply): array => [
                    'body' => (string) $reply->body,
                    'is_staff' => (bool) $reply->is_staff,
                    'created_at' => (string) $reply->created_at,
                ])
                ->values()
                ->all(),
        ], $tickets);
    }

    /**
     * Requests this partner sent with the old form on the notices page, newest first.
     *
     * Those rows are found by the "[code] " tag the form put at the start of the message. Anyone
     * can type that on the public contact form, so the name or phone on the row must match too.
     *
     * @return list<array{id: string, topic_label: string, subject: string, body: string, status: string, created_at: string, earlier: bool, replies: list<array<string, mixed>>}>
     */
    public function earlierRequests(User $user, int $limit = 40): array
    {
        if (! in_array($user->role, ['dealer', 'sub_dealer'], true)) {
            return [];
        }
        $tags = array_values(array_filter(
            [$user->dealer_code, $user->email],
            fn (?string $identity): bool => filled($identity),
        ));
        if ($tags === []) {
            return [];
        }
        $tags = array_map(fn (string $identity): string => '['.$identity.'] ', $tags);
        $phones = array_values(array_filter([$user->phone, $user->dealer_code, 'partner-'.$user->id]));

        return DB::table('enquiries')
            ->whereIn('topic', array_keys(self::TopicLabelKeys))
            ->where(function ($query) use ($tags): void {
                foreach ($tags as $tag) {
                    $query->orWhereRaw('substr(message, 1, '.mb_strlen($tag).') = ?', [$tag]);
                }
            })
            ->where(fn ($query) => $query->where('name', $user->name)->orWhereIn('phone', $phones))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'topic', 'message', 'status', 'created_at'])
            ->map(function (object $row) use ($tags): array {
                $body = (string) $row->message;
                foreach ($tags as $tag) {
                    if (str_starts_with($body, $tag)) {
                        $body = substr($body, strlen($tag));
                        break;
                    }
                }

                return [
                    'id' => 'enquiry-'.$row->id,
                    'topic_label' => __('portal.'.self::TopicLabelKeys[$row->topic]),
                    'subject' => Str::limit($body, 70),
                    'body' => $body,
                    'status' => (string) ($row->status ?: 'new'),
                    'created_at' => (string) $row->created_at,
                    'earlier' => true,
                    'replies' => [],
                ];
            })
            ->all();
    }
}
