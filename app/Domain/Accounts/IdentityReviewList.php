<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\Audit;
use App\Support\PhoneIdentity;
use Illuminate\Support\Facades\DB;

/**
 * The owner's and finance's list of accounts that look like the same person. It shows who
 * matched and how, with masked phones only: never the NID, the payout number or the hash.
 */
final class IdentityReviewList
{
    /** @var list<string> */
    public const Statuses = ['open', 'cleared', 'resolved'];

    public const PerPage = 20;

    public static function mayReview(User $user): bool
    {
        return $user->staff() && ($user->owner() || $user->allows('finance'));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, page: int, last_page: int, total: int, open_count: int}
     */
    public function page(string $status, ?string $kind, int $page): array
    {
        $query = DB::table('identity_review_flags')->where('status', $status)->when($kind !== null, fn ($flags) => $flags->where('kind', $kind));
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PerPage));
        $page = min(max(1, $page), $lastPage);
        $flags = $query->orderByDesc('last_seen_at')->orderByDesc('id')->forPage($page, self::PerPage)->get();

        $userIds = $flags->flatMap(fn (\stdClass $flag): array => (array) json_decode((string) $flag->user_ids, true, flags: JSON_THROW_ON_ERROR))->unique()->values()->all();
        $members = DB::table('users')->whereIn('id', $userIds)->get(['id', 'name', 'dealer_code', 'role', 'status', 'phone'])->keyBy('id');
        $reviewers = DB::table('users')->whereIn('id', $flags->pluck('reviewed_by')->filter()->unique()->values()->all())->pluck('name', 'id');

        $rows = $flags->map(function (\stdClass $flag) use ($members, $reviewers): array {
            $accounts = [];
            foreach ((array) json_decode((string) $flag->user_ids, true, flags: JSON_THROW_ON_ERROR) as $userId) {
                $member = $members->get($userId);
                if ($member !== null) {
                    $accounts[] = ['id' => (int) $member->id, 'name' => (string) $member->name, 'code' => $member->dealer_code, 'role' => (string) $member->role, 'status' => (string) $member->status, 'phone' => PhoneIdentity::masked($member->phone)];
                }
            }

            return [
                'id' => (int) $flag->id,
                'kind' => (string) $flag->kind,
                'via' => (array) json_decode((string) $flag->via, true, flags: JSON_THROW_ON_ERROR),
                'status' => (string) $flag->status,
                'note' => $flag->review_note,
                'reviewed_by' => $flag->reviewed_by !== null ? $reviewers->get($flag->reviewed_by) : null,
                'reviewed_at' => $flag->reviewed_at,
                'first_seen_at' => $flag->first_seen_at,
                'accounts' => $accounts,
            ];
        })->all();

        return [
            'rows' => $rows,
            'page' => $page,
            'last_page' => $lastPage,
            'total' => $total,
            'open_count' => DB::table('identity_review_flags')->where('status', 'open')->count(),
        ];
    }

    /** Record the reviewer's decision. This only changes the flag, never the accounts on it. */
    public function mark(User $reviewer, int $flagId, string $status, ?string $note): void
    {
        $updated = DB::table('identity_review_flags')->where('id', $flagId)->update([
            'status' => $status,
            'review_note' => $note,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
        abort_unless($updated === 1, 404);
        Audit::record($reviewer->id, 'identity_review.marked', 'flag:'.$flagId, ['status' => $status]);
    }
}
