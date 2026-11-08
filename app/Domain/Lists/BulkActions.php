<?php

namespace App\Domain\Lists;

use App\Domain\Inventory\BranchOperations;
use App\Http\Controllers\AccountListExportController;
use App\Models\Order;
use App\Models\User;
use App\Support\Audit;
use App\Support\PhoneIdentity;
use App\Support\SpreadsheetCell;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only things office lists may do to several rows at once: read-only files and
 * printouts, and marking your own notices read. Approving money, payouts or refunds
 * is never added here; each of those stays a one-at-a-time decision.
 */
final class BulkActions
{
    public const OrdersExport = 'orders-export';

    public const OrderSlips = 'order-slips';

    public const PartnersExport = 'partners-export';

    public const NoticesRead = 'notices-read';

    /** @var list<string> */
    public const Allowed = [self::OrdersExport, self::OrderSlips, self::PartnersExport, self::NoticesRead];

    public const MaxItems = 200;

    public function __construct(private readonly BranchOperations $branches) {}

    /**
     * Selected orders this person may open; anything outside their branch is simply left out.
     *
     * @param  list<string>  $ids
     * @return Collection<int, Order>
     */
    public function orders(User $actor, Request $request, array $ids): Collection
    {
        abort_unless(($actor->staff() || $actor->usingStaffWorkspace()) && ($actor->allows('orders') || $actor->allows('finance')), 403);
        $query = Order::query()->whereIn('orders.id', $ids);
        $this->branches->scopeFor($actor, $request)->restrictOrders($query);

        return $query->with(['user:id,name,dealer_code,role,phone,division,district', 'fulfillmentBranch:id,name,code', 'lines:id,order_id,name,sku,quantity'])
            ->orderBy('orders.created_at')
            ->get();
    }

    /**
     * @param  list<string>  $ids
     */
    public function exportOrders(User $actor, Request $request, array $ids): StreamedResponse
    {
        $orders = $this->orders($actor, $request, $ids);
        $fullContacts = AccountListExportController::mayExportFullContacts($actor);
        Audit::record($actor->id, 'report.exported', 'orders-selected', ['count' => $orders->count(), 'full_contacts' => $fullContacts]);
        $rows = $orders->map(function (Order $order) use ($fullContacts): array {
            /** @var User|null $buyer */
            $buyer = $order->user;

            return [
                $order->number,
                $order->created_at?->timezone('Asia/Dhaka')->format('Y-m-d H:i'),
                $buyer?->dealer_code,
                $buyer?->name,
                $fullContacts ? $buyer?->phone : PhoneIdentity::masked($buyer?->phone),
                $this->statusLabel((string) $order->status),
                $order->total,
                $order->paid,
                $order->outstanding(),
            ];
        });

        return $this->csv('madina-orders-selected', ['csv_col_order', 'csv_col_date_dhaka', 'csv_col_dealer_code', 'csv_col_name', 'csv_col_phone', 'csv_col_status', 'csv_col_total_bdt', 'csv_col_verified_receipts_bdt', 'csv_col_unpaid_bdt'], $rows->all());
    }

    /**
     * @param  list<int>  $ids
     */
    public function exportPartners(User $actor, Request $request, array $ids): StreamedResponse
    {
        abort_unless(($actor->staff() || $actor->usingStaffWorkspace()) && ($actor->owner() || $actor->allows('accounts')), 403);
        $query = User::query()->whereIn('users.id', $ids)->whereIn('users.role', ['dealer', 'sub_dealer']);
        $this->branches->scopeFor($actor, $request)->restrictPartners($query);
        $partners = $query->orderBy('users.name')->get(['users.id', 'users.name', 'users.dealer_code', 'users.role', 'users.status', 'users.phone', 'users.district', 'users.division']);
        $fullContacts = AccountListExportController::mayExportFullContacts($actor);
        Audit::record($actor->id, 'report.exported', 'partners-selected', ['count' => $partners->count(), 'full_contacts' => $fullContacts]);
        $rows = $partners->map(fn (User $partner): array => [
            $partner->dealer_code,
            $partner->name,
            (string) __('portal.acct_role_'.$partner->role),
            $fullContacts ? $partner->phone : PhoneIdentity::masked($partner->phone),
            $this->statusLabel((string) $partner->status),
            $partner->district,
            $partner->division,
        ]);

        return $this->csv('madina-partners-selected', ['csv_col_dealer_code', 'csv_col_name', 'csv_col_role', 'csv_col_phone', 'csv_col_status', 'csv_col_district', 'csv_col_division'], $rows->all());
    }

    /**
     * Only ever touches the signed-in person's own notices.
     *
     * @param  list<int>  $ids
     * @return int Notices still unread.
     */
    public function markNoticesRead(User $actor, array $ids): int
    {
        DB::table('account_notices')->where('user_id', $actor->id)->whereIn('id', $ids)->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return DB::table('account_notices')->where('user_id', $actor->id)->whereNull('read_at')->count();
    }

    /**
     * @param  list<string>  $columnKeys
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function csv(string $name, array $columnKeys, array $rows): StreamedResponse
    {
        $headers = array_map(fn (string $key): string => (string) __('portal.'.$key), $columnKeys);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $headers, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($file, array_map(SpreadsheetCell::text(...), $row), ',', '"', '');
            }
            fclose($file);
        }, $name.'-'.now('Asia/Dhaka')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    private function statusLabel(string $status): string
    {
        $key = 'portal.status_'.$status;
        $label = __($key);

        return $label === $key ? ucfirst($status) : (string) $label;
    }
}
