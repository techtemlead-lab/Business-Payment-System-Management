<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the order page offers finance staff before an order is approved: which
 * of the first three upline generations can no longer earn commission (the
 * partner left or was suspended), and which active partners may take that
 * place. CommissionPosting checks the chosen replacement again on approval.
 */
final class UplineReplacementOptions
{
    /** Upline generations a replacement can be picked for. */
    public const Generations = 3;

    /**
     * @return array{slots: list<array{generation: int, name: string}>, candidates: Collection<int, User>|list<never>}
     */
    public function for(User $viewer, Order $order, ?string $search = null): array
    {
        if (! $viewer->canAct('finance', 'edit') || ! in_array($order->status, ['submitted', 'held'], true)) {
            return ['slots' => [], 'candidates' => []];
        }

        $uplineIds = array_slice($order->commission_policy_snapshot['uplines'] ?? [], 0, self::Generations);
        $uplines = User::query()->whereIn('id', array_filter($uplineIds, fn ($id): bool => $id !== null))->get()->keyBy('id');
        $slots = [];
        foreach ($uplineIds as $index => $id) {
            $original = $id === null ? null : $uplines->get($id);
            if ($original && ($original->status !== 'active' || ! in_array($original->role, ['dealer', 'sub_dealer'], true))) {
                $slots[] = ['generation' => $index + 1, 'name' => $original->name];
            }
        }
        if ($slots === []) {
            return ['slots' => [], 'candidates' => []];
        }

        $candidates = User::where('status', 'active')->whereIn('role', ['dealer', 'sub_dealer'])->where('id', '!=', $order->user_id)
            ->when($search !== null && $search !== '', fn ($query) => $query->where(fn ($match) => $match->where('name', 'ilike', '%'.$search.'%')->orWhere('dealer_code', 'ilike', '%'.$search.'%')))
            ->orderBy('name')->limit(200)->get(['id', 'name', 'dealer_code']);

        return ['slots' => $slots, 'candidates' => $candidates];
    }
}
