<?php

namespace App\Domain\Orders;

use App\Domain\Inventory\BranchScope;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * For office staff: how each partner's orders went. Every figure covers orders placed in the
 * chosen period, leaves out opening balances, and is read in three grouped queries however many
 * partners are on the page. An order that came back counts as a return, not also as a cancellation.
 */
final class PartnerScorecard
{
    public const PerPage = 25;

    /**
     * @param  array{q?: string|null, buyer_role?: string|null, period?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{partners: LengthAwarePaginator<int, array<string, mixed>>}
     */
    public function page(array $filters, ?BranchScope $branchScope): array
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $role = (string) ($filters['buyer_role'] ?? '');
        $partners = User::query()
            ->whereIn('role', $role !== '' ? [$role] : ['dealer', 'sub_dealer'])
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'ilike', '%'.OrderListFilter::escapeLike($search).'%')
                ->orWhere('dealer_code', 'ilike', '%'.OrderListFilter::escapeLike($search).'%')))
            ->orderBy('name')
            ->orderBy('id');
        $branchScope?->restrictPartners($partners);
        $page = $partners->paginate(self::PerPage, ['id', 'name', 'dealer_code', 'role', 'status', 'district', 'division'])->withQueryString();
        $ids = array_map(fn (User $partner): int => (int) $partner->id, $page->items());
        $range = OrderListFilter::createdRange((string) ($filters['period'] ?? ''), $filters['from'] ?? null, $filters['to'] ?? null);
        $orders = $this->orderFigures($ids, $range);
        $payments = $this->paymentFigures($ids, $range);
        $returns = $this->returnedParcels($ids, $range);

        return ['partners' => $page->through(function (User $partner) use ($orders, $payments, $returns): array {
            $id = (int) $partner->id;

            return [
                'id' => $id,
                'name' => (string) $partner->name,
                'dealer_code' => $partner->dealer_code,
                'role' => (string) $partner->role,
                'status' => (string) $partner->status,
                'orders' => (int) ($orders[$id]->placed ?? 0),
                'cancelled' => (int) ($orders[$id]->cancelled ?? 0),
                'payments_verified' => (int) ($payments[$id]->verified ?? 0),
                'payments_verified_amount' => Money::decimal(Money::cents((string) ($payments[$id]->verified_amount ?? '0'))),
                'payments_rejected' => (int) ($payments[$id]->rejected ?? 0),
                'payments_pending' => (int) ($payments[$id]->pending ?? 0),
                'returned' => $returns[$id] ?? 0,
            ];
        })];
    }

    /**
     * @param  list<int>  $ids
     * @param  array{from: CarbonImmutable, until: CarbonImmutable}|null  $range
     * @return array<int, \stdClass>
     */
    private function orderFigures(array $ids, ?array $range): array
    {
        return $this->partnerOrders(DB::table('orders'), $ids, $range)
            ->groupBy('orders.user_id')
            ->selectRaw("orders.user_id, COUNT(*) AS placed, COUNT(*) FILTER (WHERE orders.status IN ('cancelled', 'rejected') AND NOT EXISTS (SELECT 1 FROM shipments WHERE shipments.order_id = orders.id AND shipments.status = 'returned')) AS cancelled")
            ->get()
            ->keyBy(fn (\stdClass $row): int => (int) $row->user_id)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @param  array{from: CarbonImmutable, until: CarbonImmutable}|null  $range
     * @return array<int, \stdClass>
     */
    private function paymentFigures(array $ids, ?array $range): array
    {
        return $this->partnerOrders(DB::table('payment_claims')->join('orders', 'orders.id', '=', 'payment_claims.order_id'), $ids, $range)
            ->groupBy('orders.user_id')
            ->selectRaw("orders.user_id, COUNT(*) FILTER (WHERE payment_claims.status = 'verified') AS verified, COALESCE(SUM(payment_claims.amount) FILTER (WHERE payment_claims.status = 'verified'), 0) AS verified_amount, COUNT(*) FILTER (WHERE payment_claims.status = 'rejected') AS rejected, COUNT(*) FILTER (WHERE payment_claims.status = 'pending') AS pending")
            ->get()
            ->keyBy(fn (\stdClass $row): int => (int) $row->user_id)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @param  array{from: CarbonImmutable, until: CarbonImmutable}|null  $range
     * @return array<int, int>
     */
    private function returnedParcels(array $ids, ?array $range): array
    {
        return $this->partnerOrders(DB::table('shipments')->join('orders', 'orders.id', '=', 'shipments.order_id'), $ids, $range)
            ->where('shipments.status', 'returned')
            ->groupBy('orders.user_id')
            ->selectRaw('orders.user_id, COUNT(*) AS returned')
            ->pluck('returned', 'orders.user_id')
            ->mapWithKeys(fn ($count, $userId): array => [(int) $userId => (int) $count])
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @param  array{from: CarbonImmutable, until: CarbonImmutable}|null  $range
     */
    private function partnerOrders(Builder $query, array $ids, ?array $range): Builder
    {
        $query->whereIn('orders.user_id', $ids)->where('orders.is_opening_balance', false);
        if ($range !== null) {
            $query->whereBetween('orders.created_at', [$range['from'], $range['until']]);
        }

        return $query;
    }
}
