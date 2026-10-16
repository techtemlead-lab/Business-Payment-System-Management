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

    /** @return list<int> */
    private static function ancestors(int $userId, ?int $sponsorId): array
    {
        if ($sponsorId === $userId) {
            throw new InvalidArgumentException('Self-sponsorship is disallowed.');
        }
        $seen = [$userId];
        $ids = [];
        while ($sponsorId) {
            if (in_array($sponsorId, $seen, true)) {
                throw new InvalidArgumentException('Circular hierarchy cycle detected.');
            }
            $seen[] = $sponsorId;
            $ids[] = $sponsorId;
            $sponsorId = User::whereKey($sponsorId)->firstOrFail()->sponsor_id;
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
