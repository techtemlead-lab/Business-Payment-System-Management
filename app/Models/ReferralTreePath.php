<?php

namespace App\Models;

use App\Domain\Commissions\CommissionWallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReferralTreePath extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ancestor_id');
    }

    public function descendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'descendant_id');
    }

    public static function isDescendant(int $ancestorId, int $descendantId): bool
    {
        return $ancestorId !== $descendantId && static::where('ancestor_id', $ancestorId)->where('descendant_id', $descendantId)->where('depth', '>', 0)->exists();
    }

    public static function attachUser(int $userId, ?int $sponsorId = null): void
    {
        DB::transaction(function () use ($userId, $sponsorId): void {
            CommissionWallet::lock();
            $ancestors = self::ancestors($userId, $sponsorId);
            DB::table('referral_tree_paths')->upsert([['ancestor_id' => $userId, 'descendant_id' => $userId, 'depth' => 0,
                'created_at' => now(), 'updated_at' => now()]], ['ancestor_id', 'descendant_id'], ['depth', 'updated_at']);
            foreach ($ancestors as $depth => $id) {
                DB::table('referral_tree_paths')->upsert([['ancestor_id' => $id, 'descendant_id' => $userId, 'depth' => $depth + 1,
                    'created_at' => now(), 'updated_at' => now()]], ['ancestor_id', 'descendant_id'], ['depth', 'updated_at']);
            }
        }, 3);
    }

    /**
     * The sponsor chain above a user, nearest first, read in one query.
     *
     * Walks users.sponsor_id (the source of truth the closure rows are built from) with a
     * recursive query instead of one query per level. The walk stops at the first repeated id,
     * so a loop in the data is reported as a circular hierarchy, as before.
     *
     * @return list<int>
     */
    private static function ancestors(int $userId, ?int $sponsorId): array
    {
        if ($sponsorId === $userId) {
            throw new InvalidArgumentException(__('portal.err_tree_self_sponsor'));
        }
        if (! $sponsorId) {
            return [];
        }
        $chain = DB::select(<<<'SQL'
            WITH RECURSIVE chain (id, sponsor_id, depth, seen, is_cycle) AS (
                SELECT u.id, u.sponsor_id, 0, ARRAY[CAST(? AS bigint), u.id], false
                FROM users u WHERE u.id = ?
                UNION ALL
                SELECT u.id, u.sponsor_id, c.depth + 1, c.seen || u.id, u.id = ANY(c.seen)
                FROM users u JOIN chain c ON u.id = c.sponsor_id
                WHERE NOT c.is_cycle
            )
            SELECT id, sponsor_id, is_cycle FROM chain ORDER BY depth
            SQL, [$userId, $sponsorId]);
        if ($chain === []) {
            User::whereKey($sponsorId)->firstOrFail();
        }
        $ids = [];
        foreach ($chain as $row) {
            if ($row->is_cycle) {
                throw new InvalidArgumentException(__('portal.err_tree_circular_hierarchy'));
            }
            $ids[] = (int) $row->id;
        }
        $top = end($chain);
        if ($top->sponsor_id) {
            User::whereKey($top->sponsor_id)->firstOrFail();
        }

        return $ids;
    }

    public static function reparentUser(int $userId, ?int $newSponsorId = null): void
    {
        DB::transaction(function () use ($userId, $newSponsorId): void {
            CommissionWallet::lock();
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $ancestors = self::ancestors($userId, $newSponsorId);
            self::attachUser($userId, $user->sponsor_id);
            $subtree = static::where('ancestor_id', $userId)->get();
            $descendants = $subtree->pluck('descendant_id');
            $old = static::where('descendant_id', $userId)->where('depth', '>', 0)->pluck('ancestor_id');
            DB::table('referral_tree_paths')->whereIn('ancestor_id', $old)->whereIn('descendant_id', $descendants)->delete();
            foreach ($ancestors as $depth => $ancestorId) {
                foreach ($subtree as $node) {
                    DB::table('referral_tree_paths')->upsert([['ancestor_id' => $ancestorId, 'descendant_id' => $node->descendant_id,
                        'depth' => $depth + 1 + $node->depth, 'created_at' => now(), 'updated_at' => now()]],
                        ['ancestor_id', 'descendant_id'], ['depth', 'updated_at']);
                }
            }
            $user->update(['sponsor_id' => $newSponsorId]);
        }, 3);
    }

    /** @return Collection<int, self> */
    public static function getUplines(int $userId, int $maxDepth = 3): Collection
    {
        return static::where('descendant_id', $userId)->whereBetween('depth', [1, $maxDepth])->with('ancestor')->orderBy('depth')->get();
    }
}
