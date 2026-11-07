<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OrderListFilter;
use App\Models\Order;
use App\Support\Audit;
use App\Support\DashboardManagement;
use App\Support\SpreadsheetCell;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Orders CSV. Honours the same filters as the on-screen order list
     * (search, status, period/date range, buyer type, section) so staff
     * download exactly what they are looking at.
     */
    public function export(Request $r, OrderListFilter $filter): StreamedResponse
    {
        $u = $r->user();
        $staff = $u->staff();
        abort_unless(! $staff || $u->allows('reports') || $u->allows('orders') || $u->allows('finance'), 403);
        $d = $r->validate([
            'q' => 'nullable|string|max:120',
            'status' => 'nullable|string|max:40',
            'period' => 'nullable|in:today,week,month,last_month,year,day,custom',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'buyer_role' => 'nullable|in:dealer,sub_dealer',
            'section' => 'nullable|in:orders,deliveries,reports',
        ]);
        // Older links sent only from/to; treat them as a custom date range.
        if (! $r->filled('period') && ($r->filled('from') || $r->filled('to'))) {
            $r->merge([
                'period' => 'custom',
                'from' => $d['from'] ?? '2000-01-01',
                'to' => $d['to'] ?? now('Asia/Dhaka')->toDateString(),
            ]);
        }
        $section = $d['section'] ?? 'orders';

        $q = $filter->apply(
            Order::query()->when(! $staff, fn ($q) => $q->where('user_id', $u->id)),
            $r,
            $section,
            $staff,
        )
            ->with(['user:id,name,dealer_code,role,phone,division,district', 'fulfillmentBranch:id,name,code'])
            ->withSum('lines as item_quantity', 'quantity')
            ->addSelect([
                'verified_channels' => DB::table('payment_claims')
                    ->selectRaw("string_agg(distinct channel, ', ')")
                    ->whereColumn('payment_claims.order_id', 'orders.id')
                    ->where('payment_claims.status', 'verified'),
                'verified_references' => DB::table('payment_claims')
                    ->selectRaw("string_agg(reference, ', ')")
                    ->whereColumn('payment_claims.order_id', 'orders.id')
                    ->where('payment_claims.status', 'verified'),
            ])
            ->orderBy('created_at');

        Audit::record($u->id, 'report.exported', 'orders', array_filter($d, fn ($value) => $value !== null));
        $locale = $u->preferredLocale();
        $col = fn (string $key): string => __('portal.'.$key, [], $locale);
        $headers = [
            $col('csv_col_order'),
            $col('csv_col_date_dhaka'),
            ...($staff ? [
                $col('csv_col_dealer_code'),
                $col('csv_col_name'),
                $col('csv_col_role'),
                $col('csv_col_phone'),
                $col('csv_col_district'),
                $col('csv_col_division'),
                $col('csv_col_branch'),
            ] : []),
            $col('csv_col_items'),
            $col('csv_col_status'),
            $col('csv_col_total_bdt'),
            $col('csv_col_verified_receipts_bdt'),
            $col('csv_col_unpaid_bdt'),
            $col('csv_col_payment_method'),
            $col('csv_col_payment_ref'),
        ];

        return response()->streamDownload(function () use ($q, $headers, $locale, $staff) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $headers, ',', '"', '');
            foreach ($q->lazy(500) as $o) {
                $buyer = $o->user;
                fputcsv($f, array_map(SpreadsheetCell::text(...), [
                    $o->number,
                    $o->created_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'),
                    ...($staff ? [
                        $buyer?->dealer_code,
                        $buyer?->name,
                        $buyer ? $this->localizedCsvEnum('acct_role_'.$buyer->role, (string) $buyer->role, $locale) : '',
                        $buyer?->phone,
                        $buyer?->district,
                        $buyer?->division,
                        $o->fulfillmentBranch?->name,
                    ] : []),
                    (int) ($o->item_quantity ?? 0),
                    $this->localizedCsvEnum('status_'.$o->status, (string) $o->status, $locale),
                    $o->total,
                    $o->paid,
                    $o->outstanding(),
                    $o->verified_channels,
                    $o->verified_references,
                ]), ',', '"', '');
            }
            fclose($f);
        }, 'madina-orders-'.now('Asia/Dhaka')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * CSV genealogy / downline export for dealers (own tree) and staff with accounts access.
     */
    public function genealogy(Request $r): StreamedResponse
    {
        $u = $r->user();
        $staff = $u->staff();

        if ($staff) {
            abort_unless($u->allows('accounts') || $u->allows('reports'), 403);
        } else {
            abort_unless($u->role === 'dealer', 403);
            abort_unless(DashboardManagement::partnerFeatureEnabled($u, 'network'), 403);
        }

        Audit::record($u->id, 'report.exported', 'genealogy', [
            'scope' => $staff ? 'company' : 'downline',
        ]);

        $locale = $u->preferredLocale();
        $headers = [
            __('portal.csv_col_dealer_code', [], $locale),
            __('portal.csv_col_name', [], $locale),
            __('portal.csv_col_phone', [], $locale),
            __('portal.csv_col_role', [], $locale),
            __('portal.csv_col_rank', [], $locale),
            __('portal.csv_col_status', [], $locale),
            __('portal.csv_col_sponsor_code', [], $locale),
            __('portal.csv_col_sponsor_name', [], $locale),
            __('portal.csv_col_division', [], $locale),
            __('portal.csv_col_district', [], $locale),
            __('portal.csv_col_depth', [], $locale),
            __('portal.csv_col_lifetime_purchases_bdt', [], $locale),
            __('portal.csv_col_joined_dhaka', [], $locale),
        ];

        $query = $staff
            ? DB::table('users')
                ->leftJoin('users as sponsors', 'sponsors.id', '=', 'users.sponsor_id')
                ->leftJoin('dealer_ranks', 'dealer_ranks.user_id', '=', 'users.id')
                ->whereIn('users.role', ['dealer', 'sub_dealer'])
                ->orderBy('users.id')
                ->select([
                    'users.dealer_code',
                    'users.name',
                    'users.phone',
                    'users.role',
                    'users.status',
                    'users.division',
                    'users.district',
                    'users.created_at',
                    'sponsors.dealer_code as sponsor_code',
                    'sponsors.name as sponsor_name',
                    'dealer_ranks.current_rank',
                    'dealer_ranks.lifetime_purchase_total',
                    DB::raw('NULL as depth'),
                ])
            : DB::table('referral_tree_paths')
                ->join('users', 'users.id', '=', 'referral_tree_paths.descendant_id')
                ->leftJoin('users as sponsors', 'sponsors.id', '=', 'users.sponsor_id')
                ->leftJoin('dealer_ranks', 'dealer_ranks.user_id', '=', 'users.id')
                ->where('referral_tree_paths.ancestor_id', $u->id)
                ->where('referral_tree_paths.depth', '>', 0)
                ->orderBy('referral_tree_paths.depth')
                ->orderBy('users.id')
                ->select([
                    'users.dealer_code',
                    'users.name',
                    'users.phone',
                    'users.role',
                    'users.status',
                    'users.division',
                    'users.district',
                    'users.created_at',
                    'sponsors.dealer_code as sponsor_code',
                    'sponsors.name as sponsor_name',
                    'dealer_ranks.current_rank',
                    'dealer_ranks.lifetime_purchase_total',
                    'referral_tree_paths.depth',
                ]);

        $filename = $staff
            ? 'madina-genealogy-company-'.now()->format('Y-m-d').'.csv'
            : 'madina-genealogy-downline-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query, $headers, $locale) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $headers, ',', '"', '');
            foreach ($query->cursor() as $row) {
                $joined = $row->created_at
                    ? Carbon::parse($row->created_at)->timezone('Asia/Dhaka')->format('Y-m-d H:i')
                    : '';
                $rank = $row->current_rank ?: 'dealer';
                fputcsv($f, array_map(SpreadsheetCell::text(...), [
                    $row->dealer_code,
                    $row->name,
                    $row->phone,
                    $this->localizedCsvEnum('acct_role_'.$row->role, (string) $row->role, $locale),
                    $this->localizedCsvEnum('net_rank_'.$rank, (string) $rank, $locale),
                    $this->localizedCsvEnum('status_'.$row->status, (string) $row->status, $locale),
                    $row->sponsor_code,
                    $row->sponsor_name,
                    $row->division,
                    $row->district,
                    $row->depth,
                    $row->lifetime_purchase_total ?? '0.00',
                    $joined,
                ]), ',', '"', '');
            }
            fclose($f);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    private function localizedCsvEnum(string $key, string $fallback, string $locale): string
    {
        $label = __('portal.'.$key, [], $locale);

        return $label === 'portal.'.$key ? $fallback : $label;
    }
}
