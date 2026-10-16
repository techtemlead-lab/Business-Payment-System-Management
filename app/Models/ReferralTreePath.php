<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReferralTreePath extends Model
{
    protected $guarded = [];
    public $incrementing = false;
    protected $primaryKey = ['ancestor_id', 'descendant_id'];

    public function ancestor()
    {
        return $this->belongsTo(User::class, 'ancestor_id');
    }

    public function descendant()
    {
        return $this->belongsTo(User::class, 'descendant_id');
    }

    /**
     * Check if a user is a downline descendant of an ancestor.
     */
    public static function isDescendant(int $ancestorId, int $descendantId): bool
    {
        if ($ancestorId === $descendantId) {
            return false;
        }

        return static::where('ancestor_id', $ancestorId)
            ->where('descendant_id', $descendantId)
            ->where('depth', '>', 0)
            ->exists();
    }

    /**
     * Attach a new user to the closure tree under an optional sponsor with cycle detection.
     */
    public static function attachUser(int $userId, ?int $sponsorId = null): void
    {
        // 1. Self path (depth = 0)
        static::firstOrCreate([
            'ancestor_id' => $userId,
            'descendant_id' => $userId,
            'depth' => 0,
        ]);

        if (!$sponsorId) {
            return;
        }

        // Guard 1: Prevent self-sponsorship
        if ($sponsorId === $userId) {
            throw new InvalidArgumentException("Self-sponsorship is disallowed: User #{$userId} cannot be their own sponsor.");
        }

        // Guard 2: Prevent cycle - proposed sponsor cannot be a downline descendant
        if (static::isDescendant($userId, $sponsorId)) {
            throw new InvalidArgumentException("Circular hierarchy cycle detected: Sponsor #{$sponsorId} is already a downline descendant of User #{$userId}.");
        }

        // 2. Inherit uplines from sponsor
        $ancestors = static::where('descendant_id', $sponsorId)->get();
        foreach ($ancestors as $a) {
            static::firstOrCreate([
                'ancestor_id' => $a->ancestor_id,
                'descendant_id' => $userId,
                'depth' => $a->depth + 1,
            ]);
        }
    }

    /**
     * Re-parent an existing user and their entire downline sub-tree to a new sponsor.
     */
    public static function reparentUser(int $userId, ?int $newSponsorId = null): void
    {
        if ($newSponsorId === $userId) {
            throw new InvalidArgumentException("Self-sponsorship is disallowed: User #{$userId} cannot be their own sponsor.");
        }

        if ($newSponsorId && static::isDescendant($userId, $newSponsorId)) {
            throw new InvalidArgumentException("Circular hierarchy cycle detected: New sponsor #{$newSponsorId} is a downline descendant of User #{$userId}.");
        }

        DB::transaction(function () use ($userId, $newSponsorId) {
            // 1. Fetch entire sub-tree rooted at $userId
            $subTree = static::where('ancestor_id', $userId)->get();
            $descendantIds = $subTree->pluck('descendant_id')->unique()->all();

            // 2. Fetch old ancestors of $userId (excluding $userId itself)
            $oldAncestorIds = static::where('descendant_id', $userId)
                ->where('ancestor_id', '!=', $userId)
                ->pluck('ancestor_id')
                ->unique()
                ->all();

            // 3. Sever paths connecting old ancestors to the moving sub-tree
            if (!empty($oldAncestorIds) && !empty($descendantIds)) {
                static::whereIn('ancestor_id', $oldAncestorIds)
                    ->whereIn('descendant_id', $descendantIds)
                    ->delete();
            }

            // 4. Attach moving sub-tree to new sponsor if specified
            if ($newSponsorId) {
                // Ensure new sponsor exists in tree
                static::firstOrCreate([
                    'ancestor_id' => $newSponsorId,
                    'descendant_id' => $newSponsorId,
                    'depth' => 0,
                ]);

                $newAncestors = static::where('descendant_id', $newSponsorId)->get();
                foreach ($newAncestors as $na) {
                    foreach ($subTree as $subNode) {
                        static::firstOrCreate([
                            'ancestor_id' => $na->ancestor_id,
                            'descendant_id' => $subNode->descendant_id,
                            'depth' => $na->depth + 1 + $subNode->depth,
                        ]);
                    }
                }
            }
        });
    }

    /**
     * Get direct upline ancestors up to specified depth.
     */
    public static function getUplines(int $userId, int $maxDepth = 3)
    {
        return static::where('descendant_id', $userId)
            ->where('depth', '>', 0)
            ->where('depth', '<=', $maxDepth)
            ->with('ancestor')
            ->orderBy('depth', 'asc')
            ->get();
    }
}
