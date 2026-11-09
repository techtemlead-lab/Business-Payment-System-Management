<?php

namespace App\Domain\Orders;

use App\Domain\Inventory\BranchScope;
use App\Support\Money;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * For office staff: how each partner's orders went. Every figure covers orders placed in the
 * chosen period and leaves out opening balances. The figures are joined to every partner, so
 * search and column sort cover all partners and not only the page on screen. An order that came
 * back counts as a return, not also as a cancellation.
 */
final class PartnerScorecard
{
    public const PerPage = 25;

    private const ExtraPeriods = ['last_month'];

    /**
     * @return array{partners: array<string, mixed>} the partners as a MoneyTable payload
     */
    public function page(Request $request, ?BranchScope $branchScope): array
    {
        $range = MoneyTable::range($request, null, self::ExtraPeriods);
        $partners = DB::table('users')
            ->leftJoinSub($this->orderFigures($range), 'order_figures', 'order_figures.user_id', '=', 'users.id')
            ->leftJoinSub($this->paymentFigures($range), 'payment_figures', 'payment_figures.user_id', '=', 'users.id')
            ->leftJoinSub($this->returnedParcels($range), 'returned_parcels', 'returned_parcels.user_id', '=', 'users.id')
            ->whereIn('users.role', ['dealer', 'sub_dealer'])
            ->selectRaw('users.id, users.name, users.dealer_code, users.role, users.status, '
                .'COALESCE(order_figures.placed, 0) AS placed, COALESCE(order_figures.cancelled, 0) AS cancelled, '
                .'COALESCE(payment_figures.verified, 0) AS payments_verified, COALESCE(payment_figures.verified_amount, 0) AS payments_verified_amount, '
                .'COALESCE(payment_figures.rejected, 0) AS payments_rejected, COALESCE(payment_figures.pending, 0) AS payments_pending, '
                .'COALESCE(returned_parcels.returned, 0) AS returned');
        $branchScope?->restrictPartners($partners);

        $table = MoneyTable::make($request, DB::query()->fromSub($partners, 'x'), new MoneyTableSpec(
            dateColumn: null,
            search: ['x.name', 'x.dealer_code'],
            filters: [new MoneyTableFilter('buyer_role', (string) __('dealer_tools.scorecard_partner_type'), 'x.role', array_map(
                fn (string $role): array => ['value' => $role, 'label' => (string) __('treasury.role_'.$role)],
                ['dealer', 'sub_dealer'],
            ))],
            sorts: [
                'name' => 'x.name',
                'orders' => 'x.placed',
                'payments_verified' => 'x.payments_verified',
                'payments_verified_amount' => 'x.payments_verified_amount',
                'payments_rejected' => 'x.payments_rejected',
                'payments_pending' => 'x.payments_pending',
                'cancelled' => 'x.cancelled',
                'returned' => 'x.returned',
            ],
            defaultSort: 'name',
            tieBreaker: 'x.id',
            defaultDir: 'asc',
            defaultPeriod: null,
            perPage: self::PerPage,
            extraPeriods: self::ExtraPeriods,
        ));
        $table['rows'] = $table['rows']->through(fn (object $partner): array => [
            'id' => (int) $partner->id,
            'name' => (string) $partner->name,
            'dealer_code' => $partner->dealer_code,
            'role' => (string) $partner->role,
            'status' => (string) $partner->status,
            'orders' => (int) $partner->placed,
            'cancelled' => (int) $partner->cancelled,
            'payments_verified' => (int) $partner->payments_verified,
            'payments_verified_amount' => Money::decimal(Money::cents((string) $partner->payments_verified_amount)),
            'payments_rejected' => (int) $partner->payments_rejected,
            'payments_pending' => (int) $partner->payments_pending,
            'returned' => (int) $partner->returned,
        ]);

        return ['partners' => $table];
    }

    /**
     * @param  array{from: CarbonImmutable, until: CarbonImmutable}|null  $range
     */
    private function orderFigures(?array $range): Builder
    {
        return $this->partnerOrders(DB::table('orders'), $range)
            ->groupBy('orders.user_id')
            ->selectRaw("orders.user_id, COUNT(*) AS placed, COUNT(*) FILTER (WHERE orders.status IN ('cancelled', 'rejected') AND NOT EXISTS (SELECT 1 FROM shipments WHERE shipments.order_id = orders.id AND shipments.status = 'returned')) AS cancelled");
    }

    /**
     * @param  array{from: CarbonImmutable, until: CarbonImmutable}|null  $range
     */
    private function paymentFigures(?array $range): Builder
    {
        return $this->partnerOrders(DB::table('payment_claims')->join('orders', 'orders.id', '=', 'payment_claims.order_id'), $range)
            ->groupBy('orders.user_id')
            ->selectRaw("orders.user_id, COUNT(*) FILTER (WHERE payment_claims.status = 'verified') AS verified, COALESCE(SUM(payment_claims.amount) FILTER (WHERE payment_claims.status = 'verified'), 0) AS verified_amount, COUNT(*) FILTER (WHERE payment_claims.status = 'rejected') AS rejected, COUNT(*) FILTER (WHERE payment_claims.status = 'pending') AS pending");
    }

    /**
     * @param  array{from: CarbonImmutable, until: CarbonImmutable}|null  $range
     */
    private function returnedParcels(?array $range): Builder
    {
        return $this->partnerOrders(DB::table('shipments')->join('orders', 'orders.id', '=', 'shipments.order_id'), $range)
            ->where('shipments.status', 'returned')
            ->groupBy('orders.user_id')
            ->selectRaw('orders.user_id, COUNT(*) AS returned');
    }

    /**
     * @param  array{from: CarbonImmutable, until: CarbonImmutable}|null  $range
     */
    private function partnerOrders(Builder $query, ?array $range): Builder
    {
        $query->where('orders.is_opening_balance', false);
        if ($range !== null) {
            $query->whereBetween('orders.created_at', [$range['from'], $range['until']]);
        }

        return $query;
    }
}
