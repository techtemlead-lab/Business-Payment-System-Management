<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\Audit;
use App\Support\DashboardManagement;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function export(Request $r)
    {
        $u = $r->user();
        abort_unless(! $u->staff() || $u->allows('reports') || $u->allows('orders') || $u->allows('finance'), 403);
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $q = Order::when(! $u->staff(), fn ($q) => $q->where('user_id', $u->id))->when(isset($d['from']), fn ($q) => $q->where('created_at', '>=', Carbon::parse($d['from'], 'Asia/Dhaka')->utc()))->when(isset($d['to']), fn ($q) => $q->where('created_at', '<', Carbon::parse($d['to'], 'Asia/Dhaka')->addDay()->utc()))->orderBy('created_at');
        Audit::record($u->id, 'report.exported', 'orders', $d);
        $locale = $u->preferredLocale();
        $headers = [
            __('portal.csv_col_order', [], $locale),
            __('portal.csv_col_date_dhaka', [], $locale),
            __('portal.csv_col_status', [], $locale),
            __('portal.csv_col_total_bdt', [], $locale),
            __('portal.csv_col_verified_receipts_bdt', [], $locale),
            __('portal.csv_col_unpaid_bdt', [], $locale),
        ];

        return response()->streamDownload(function () use ($q, $headers, $locale) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $headers, ',', '"', '');
            foreach ($q->cursor() as $o) {
                fputcsv($f, [
                    $o->number,
                    $o->created_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'),
                    $this->localizedCsvEnum('status_'.$o->status, (string) $o->status, $locale),
                    $o->total,
                    $o->paid,
                    $o->outstanding(),
                ], ',', '"', '');
            }
            fclose($f);
        }, 'madina-orders-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store']);
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
                fputcsv($f, [
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
                ], ',', '"', '');
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
