<?php

namespace App\Domain\Orders;

use App\Domain\Accounts\PartnerAppointment;
use App\Domain\Finance\PendingPaymentChecks;
use App\Domain\Inventory\BranchScope;
use App\Domain\Staff\LeadershipAccess;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CR-18: the one tabbed table under the office dashboard's buttons. Each tab is a short, searchable,
 * paged list that reads the same tables and follows the same permission and branch rules as the
 * full page it links to, so nobody sees a row there that the full page would hide. Only the open
 * tab's rows are built; the page asks for them when a tab opens.
 */
final class OfficeDashboardTabs
{
    /** Tabs in the order they show, with the person's right that opens the full list behind each. */
    public const Tabs = ['orders', 'accounts', 'withdrawals', 'payments', 'applications', 'low_stock'];

    public const PerPage = 8;

    /**
     * The tabs this person may open, in display order.
     *
     * @return list<string>
     */
    public static function visibleTo(User $viewer): array
    {
        return array_values(array_filter(self::Tabs, fn (string $tab): bool => self::mayOpen($viewer, $tab)));
    }

    public static function mayOpen(User $viewer, string $tab): bool
    {
        return match ($tab) {
            'orders' => $viewer->allows('orders') || $viewer->allows('finance'),
            'accounts' => LeadershipAccess::granted($viewer, 'accounts') || $viewer->allows('accounts'),
            'applications' => $viewer->allows('accounts'),
            'withdrawals', 'payments' => $viewer->allows('finance'),
            'low_stock' => $viewer->allows('inventory'),
            default => false,
        };
    }

    /**
     * The open tab's rows. An unknown or forbidden tab falls back to the first tab the person may open.
     *
     * @return array{tab: string, columns: list<array{key: string, type: string}>, rows: list<array<string, mixed>>, total: int, page: int, last_page: int, all_href: string, search: string}|null
     */
    public function forRequest(User $viewer, Request $request, ?BranchScope $scope): ?array
    {
        $visible = self::visibleTo($viewer);
        if ($visible === []) {
            return null;
        }
        $asked = (string) $request->query('tab', '');
        $tab = in_array($asked, $visible, true) ? $asked : $visible[0];
        $search = mb_substr(trim((string) $request->query('tq', '')), 0, 100);
        $page = max(1, min(1000, (int) $request->query('tp', 1)));
        $term = $search === '' ? null : '%'.OrderListFilter::escapeLike($search).'%';

        [$columns, $total, $rows, $allHref] = match ($tab) {
            'orders' => $this->orders($scope, $search, $page),
            'accounts' => $this->accounts($scope, $term, $page),
            'applications' => $this->applications($term, $page),
            'withdrawals' => $this->withdrawals($scope, $term, $page),
            'payments' => $this->payments($scope, $term, $page),
            default => $this->lowStock($scope, $term, $page),
        };

        return [
            'tab' => $tab,
            'columns' => $columns,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'last_page' => max(1, (int) ceil($total / self::PerPage)),
            'all_href' => $allHref,
            'search' => $search,
        ];
    }

    /** @return array{list<array{key: string, type: string}>, int, list<array<string, mixed>>, string} */
    private function orders(?BranchScope $scope, string $search, int $page): array
    {
        $orders = Order::query();
        $scope?->restrictOrders($orders);
        if ($search !== '') {
            // The order list's own search: number, partner name, code or phone.
            app(OrderListFilter::class)->apply($orders, Request::create('/', 'GET', ['q' => $search]), 'orders', true);
        }
        $total = (clone $orders)->count();
        $found = $orders->with(['user:id,name,dealer_code,role,phone,division,district,profile', 'lines:id,order_id,quantity'])->latest('created_at')->latest('id')->forPage($page, self::PerPage)
            ->select('orders.*')->selectRaw(OrderListFigures::MrpSql.' AS mrp_total')
            ->get();
        // The address is written before attachTypes hides the saved profile; both read no further rows.
        $addresses = $found->mapWithKeys(fn (Order $order): array => [$order->id => OrderListFigures::addressLine($order->user)]);
        app(PartnerAppointment::class)->attachTypes($found->map(fn (Order $order) => $order->user));
        $rows = $found->map(fn (Order $order): array => $this->orderRow($order, $addresses[$order->id] ?? null))->all();

        return [[
            ['key' => 'number', 'type' => 'text'], ['key' => 'created_at', 'type' => 'date'], ['key' => 'partner', 'type' => 'text'], ['key' => 'code', 'type' => 'text'],
            ['key' => 'partner_type', 'type' => 'partner_type'], ['key' => 'phone', 'type' => 'text'], ['key' => 'address', 'type' => 'text'], ['key' => 'items', 'type' => 'count'],
            ['key' => 'mrp', 'type' => 'money'], ['key' => 'total', 'type' => 'money'], ['key' => 'paid', 'type' => 'money'], ['key' => 'to_pay', 'type' => 'money'],
            ['key' => 'status', 'type' => 'status'],
        ], $total, $rows, '/orders'];
    }

    /** @return array<string, mixed> */
    private function orderRow(Order $order, ?string $address): array
    {
        $buyer = $order->user;
        $type = $buyer?->getAttribute('partner_type');

        return [
            'href' => '/orders/'.$order->id,
            'number' => $order->number,
            'created_at' => $order->created_at?->toIso8601String(),
            'partner' => $buyer?->getAttribute('name'),
            'code' => $buyer?->getAttribute('dealer_code'),
            'partner_type' => $buyer ? ['title' => data_get($type, 'title'), 'place' => data_get($type, 'place'), 'role' => $buyer->getAttribute('role')] : null,
            'phone' => $buyer?->getAttribute('phone'),
            'address' => $address,
            'items' => (int) $order->lines->sum('quantity'),
            'mrp' => Money::decimal(Money::cents((string) $order->getAttribute('mrp_total'))),
            'total' => (string) $order->total,
            'paid' => (string) $order->paid,
            'to_pay' => OrderListFigures::toPay($order),
            'status' => $order->status,
        ];
    }

    /** @return array{list<array{key: string, type: string}>, int, list<array<string, mixed>>, string} */
    private function accounts(?BranchScope $scope, ?string $term, int $page): array
    {
        $partners = DB::table('users')->whereIn('users.role', ['dealer', 'sub_dealer']);
        $scope?->restrictPartners($partners);
        if ($term !== null) {
            $partners->where(fn (QueryBuilder $match) => $match->where('users.name', 'ilike', $term)->orWhere('users.dealer_code', 'ilike', $term)->orWhere('users.phone', 'ilike', $term));
        }
        $total = (clone $partners)->count();
        $rows = $partners->orderByDesc('users.created_at')->orderByDesc('users.id')->forPage($page, self::PerPage)
            ->get(['users.id', 'users.name', 'users.dealer_code', 'users.role', 'users.status', 'users.district', 'users.created_at'])
            ->map(fn (object $row): array => [
                'href' => '/accounts/'.$row->id,
                'name' => trim($row->name.' '.($row->dealer_code ? '('.$row->dealer_code.')' : '')),
                'role' => $row->role,
                'district' => $row->district,
                'status' => $row->status,
                'created_at' => $this->iso($row->created_at),
            ])->all();

        return [[
            ['key' => 'name', 'type' => 'text'], ['key' => 'role', 'type' => 'role'], ['key' => 'district', 'type' => 'text'],
            ['key' => 'status', 'type' => 'status'], ['key' => 'created_at', 'type' => 'date'],
        ], $total, $rows, '/accounts'];
    }

    /** @return array{list<array{key: string, type: string}>, int, list<array<string, mixed>>, string} */
    private function applications(?string $term, int $page): array
    {
        $applications = DB::table('dealer_applications')->where('status', 'pending');
        if ($term !== null) {
            $applications->where(fn (QueryBuilder $match) => $match->whereRaw("data->>'name' ilike ?", [$term])->orWhereRaw("data->>'phone' ilike ?", [$term])->orWhereRaw("data->>'district' ilike ?", [$term]));
        }
        $total = (clone $applications)->count();
        $rows = $applications->orderByDesc('created_at')->forPage($page, self::PerPage)
            ->selectRaw("id, data->>'name' AS name, data->>'phone' AS phone, data->>'district' AS district, created_at")->get()
            ->map(fn (object $row): array => [
                'href' => '/partnerships',
                'name' => $row->name,
                'phone' => $row->phone,
                'district' => $row->district,
                'created_at' => $this->iso($row->created_at),
            ])->all();

        return [[
            ['key' => 'name', 'type' => 'text'], ['key' => 'phone', 'type' => 'text'], ['key' => 'district', 'type' => 'text'], ['key' => 'created_at', 'type' => 'date'],
        ], $total, $rows, '/partnerships'];
    }

    /** @return array{list<array{key: string, type: string}>, int, list<array<string, mixed>>, string} */
    private function withdrawals(?BranchScope $scope, ?string $term, int $page): array
    {
        $requests = DB::table('withdrawals')->join('users', 'users.id', '=', 'withdrawals.user_id');
        $scope?->restrictPartners($requests);
        if ($term !== null) {
            $requests->where(fn (QueryBuilder $match) => $match->where('users.name', 'ilike', $term)->orWhere('users.dealer_code', 'ilike', $term)->orWhere('users.phone', 'ilike', $term)->orWhere('withdrawals.payment_reference', 'ilike', $term));
        }
        $total = (clone $requests)->count();
        // Requests waiting for a decision come first, then the newest.
        $rows = $requests->orderByRaw("(withdrawals.status = 'requested') DESC")->orderByDesc('withdrawals.created_at')->forPage($page, self::PerPage)
            ->get(['withdrawals.id', 'users.name', 'users.dealer_code', 'withdrawals.amount', 'withdrawals.channel', 'withdrawals.status', 'withdrawals.created_at'])
            ->map(fn (object $row): array => [
                'href' => '/withdrawals/'.$row->id,
                'partner' => trim($row->name.' '.($row->dealer_code ? '('.$row->dealer_code.')' : '')),
                'amount' => (string) $row->amount,
                'channel' => $row->channel,
                'status' => $row->status,
                'created_at' => $this->iso($row->created_at),
            ])->all();

        return [[
            ['key' => 'partner', 'type' => 'text'], ['key' => 'amount', 'type' => 'money'], ['key' => 'channel', 'type' => 'channel'],
            ['key' => 'status', 'type' => 'status'], ['key' => 'created_at', 'type' => 'date'],
        ], $total, $rows, '/payouts-hub'];
    }

    /** @return array{list<array{key: string, type: string}>, int, list<array<string, mixed>>, string} */
    private function payments(?BranchScope $scope, ?string $term, int $page): array
    {
        $claims = PendingPaymentChecks::query()->join('orders', 'orders.id', '=', 'payment_claims.order_id')->join('users', 'users.id', '=', 'orders.user_id');
        $scope?->restrictOrders($claims);
        if ($term !== null) {
            $claims->where(fn (QueryBuilder $match) => $match->where('orders.number', 'ilike', $term)->orWhere('users.name', 'ilike', $term)->orWhere('payment_claims.reference', 'ilike', $term));
        }
        $total = (clone $claims)->count();
        $rows = $claims->orderBy('payment_claims.created_at')->forPage($page, self::PerPage)
            ->get(['payment_claims.id', 'orders.id as order_id', 'orders.number', 'users.name', 'payment_claims.channel', 'payment_claims.reference', 'payment_claims.amount', 'payment_claims.created_at'])
            ->map(fn (object $row): array => [
                'href' => '/orders/'.$row->order_id,
                'number' => $row->number,
                'partner' => $row->name,
                'channel' => $row->channel,
                'reference' => $row->reference,
                'amount' => (string) $row->amount,
                'created_at' => $this->iso($row->created_at),
            ])->all();

        return [[
            ['key' => 'number', 'type' => 'text'], ['key' => 'partner', 'type' => 'text'], ['key' => 'channel', 'type' => 'channel'],
            ['key' => 'reference', 'type' => 'text'], ['key' => 'amount', 'type' => 'money'], ['key' => 'created_at', 'type' => 'date'],
        ], $total, $rows, '/payments?status=pending'];
    }

    /** @return array{list<array{key: string, type: string}>, int, list<array<string, mixed>>, string} */
    private function lowStock(?BranchScope $scope, ?string $term, int $page): array
    {
        $products = app(StaffDashboardSummary::class)->lowStockProducts($scope);
        if ($term !== null) {
            $products->where(fn (EloquentBuilder $match) => $match->where('products.name', 'ilike', $term)->orWhere('products.sku', 'ilike', $term));
        }
        $total = DB::query()->fromSub($products->toBase(), 'low')->count();
        $rows = $products->orderBy('stock')->orderBy('products.name')->forPage($page, self::PerPage)->get()
            ->map(fn ($product): array => [
                'name' => $product->name,
                'sku' => $product->sku,
                'stock' => (string) $product->stock,
            ])->all();

        return [[
            ['key' => 'name', 'type' => 'text'], ['key' => 'sku', 'type' => 'text'], ['key' => 'stock', 'type' => 'count'],
        ], $total, $rows, '/inventory'];
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
