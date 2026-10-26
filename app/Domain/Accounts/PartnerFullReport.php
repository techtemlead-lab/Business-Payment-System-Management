<?php

namespace App\Domain\Accounts;

use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PartnerFullReport
{
    /** @var list<string> */
    private const CategoryKeys = [
        'own_dealer_cashback',
        'direct_referral',
        'direct_referral_breakage',
        'd2d_gen2',
        'd2d_gen2_breakage',
        'd2d_gen3',
        'd2d_gen3_breakage',
        'd2d_multitier',
        'area_division',
        'area_district',
        'area_upazila',
        'area_union',
        'area_management',
        'dealer_monthly_salary_pool',
        'sub_dealer_salary',
        'sub_dealer_tour',
        'additional_rank_pool',
        'rank_royalty_pool',
        'rank_incentive_pool',
        'car_bike_incentive_fund',
        'company_marketing_fund',
    ];

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, ?string $from = null, ?string $to = null): array
    {
        [$fromUtc, $toUtc] = $this->bounds($from, $to);
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['submitted', 'held', 'approved', 'partially_dispatched', 'dispatched', 'delivered'])
            ->when($fromUtc, fn ($query) => $query->where('created_at', '>=', $fromUtc))
            ->when($toUtc, fn ($query) => $query->where('created_at', '<', $toUtc))
            ->orderByDesc('created_at')
            ->get(['id', 'number', 'status', 'total', 'paid', 'due_credit', 'created_at']);

        $orderIds = $orders->pluck('id');
        $claims = $orderIds->isEmpty()
            ? collect()
            : DB::table('payment_claims')
                ->whereIn('order_id', $orderIds)
                ->orderByDesc('id')
                ->get(['id', 'order_id', 'channel', 'reference', 'amount', 'status', 'created_at'])
                ->groupBy('order_id');

        $ordered = 0;
        $paid = 0;
        $due = 0;
        $orderRows = [];
        foreach ($orders as $order) {
            $ordered += Money::cents((string) $order->total);
            $paid += Money::cents((string) $order->paid);
            $due += Money::cents($order->outstanding());
            $orderClaims = ($claims[$order->id] ?? collect())->map(fn ($claim) => [
                'channel' => $claim->channel,
                'reference' => $claim->reference,
                'amount' => Money::decimal(Money::cents((string) $claim->amount)),
                'status' => $claim->status,
                'created_at' => $claim->created_at,
            ])->values()->all();
            $orderRows[] = [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'total' => Money::decimal(Money::cents((string) $order->total)),
                'paid' => Money::decimal(Money::cents((string) $order->paid)),
                'due' => $order->outstanding(),
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'payments' => $orderClaims,
            ];
        }

        $allocations = DB::table('commission_allocations')
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['reversed'])
            ->when($fromUtc, fn ($query) => $query->where('created_at', '>=', $fromUtc))
            ->when($toUtc, fn ($query) => $query->where('created_at', '<', $toUtc))
            ->selectRaw('category, COALESCE(SUM(amount), 0) as total, COUNT(*) as entries')
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        $commissionTotal = 0;
        $byCategory = [];
        foreach ($allocations as $row) {
            $cents = Money::cents((string) $row->total);
            $commissionTotal += $cents;
            $byCategory[] = [
                'category' => $row->category,
                'label' => in_array((string) $row->category, self::CategoryKeys, true)
                    ? __('portal.report_cat_'.$row->category)
                    : str_replace('_', ' ', (string) $row->category),
                'amount' => Money::decimal($cents),
                'entries' => (int) $row->entries,
            ];
        }

        $teamIds = ReferralTreePath::query()
            ->where('ancestor_id', $user->id)
            ->where('depth', '>', 0)
            ->pluck('descendant_id');

        $teamMembers = $teamIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $teamIds)->orderBy('name')->get(['id', 'name', 'dealer_code', 'role', 'phone', 'status']);

        $teamOrders = $teamIds->isEmpty()
            ? collect()
            : Order::query()
                ->whereIn('user_id', $teamIds)
                ->whereIn('status', ['approved', 'partially_dispatched', 'dispatched', 'delivered'])
                ->when($fromUtc, fn ($query) => $query->where('created_at', '>=', $fromUtc))
                ->when($toUtc, fn ($query) => $query->where('created_at', '<', $toUtc))
                ->latest()
                ->limit(40)
                ->get(['id', 'user_id', 'number', 'status', 'total', 'paid', 'due_credit', 'created_at']);

        $teamOrderUserIds = $teamOrders->pluck('user_id')->unique();
        $teamNames = $teamMembers->keyBy('id');

        $teamActivity = $teamOrders->map(function (Order $order) use ($teamNames) {
            $member = $teamNames->get($order->user_id);

            return [
                'order_id' => $order->id,
                'number' => $order->number,
                'member_name' => $member?->name ?? '—',
                'member_code' => $member?->dealer_code,
                'status' => $order->status,
                'total' => Money::decimal(Money::cents((string) $order->total)),
                'paid' => Money::decimal(Money::cents((string) $order->paid)),
                'due' => $order->outstanding(),
                'created_at' => optional($order->created_at)?->toIso8601String(),
            ];
        })->values()->all();

        $teamCommission = $teamIds->isEmpty()
            ? '0.00'
            : Money::decimal(Money::cents((string) DB::table('commission_allocations')
                ->whereIn('user_id', $teamIds)
                ->whereNotIn('status', ['reversed'])
                ->when($fromUtc, fn ($query) => $query->where('created_at', '>=', $fromUtc))
                ->when($toUtc, fn ($query) => $query->where('created_at', '<', $toUtc))
                ->sum('amount')));

        return [
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],
            'summary' => [
                'orders_count' => $orders->count(),
                'ordered' => Money::decimal($ordered),
                'paid' => Money::decimal($paid),
                'due' => Money::decimal($due),
                'commission' => Money::decimal($commissionTotal),
                'team_members' => $teamMembers->count(),
                'team_commission' => $teamCommission,
            ],
            'commissions' => $byCategory,
            'orders' => $orderRows,
            'team_members' => $teamMembers->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'dealer_code' => $member->dealer_code,
                'role' => $member->role,
                'phone' => $member->phone,
                'status' => $member->status,
            ])->values()->all(),
            'team_activity' => $teamActivity,
            'team_active_buyers' => $teamOrderUserIds->count(),
        ];
    }

    public function downloadPdf(User $user, ?string $from = null, ?string $to = null): BinaryFileResponse
    {
        $report = $this->for($user, $from, $to);
        $summary = $report['summary'];
        $locale = $user->preferredLocale();
        $t = static fn (string $key, array $replace = []) => __('portal.'.$key, $replace, $locale);
        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($user->dealer_code ?: $user->id)) ?: (string) $user->id;
        $path = $dir.'/partner-full-report-'.$safeCode.'-'.now('Asia/Dhaka')->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';

        $none = $t('pdf_none');
        $rows = static function (array $items, callable $cells) use ($none): string {
            if ($items === []) {
                return '<tr><td colspan="6">'.e($none).'</td></tr>';
            }
            $html = '';
            foreach ($items as $item) {
                $html .= '<tr>';
                foreach ($cells($item) as $cell) {
                    $html .= '<td>'.e((string) $cell).'</td>';
                }
                $html .= '</tr>';
            }

            return $html;
        };

        $roleKey = 'acct_role_'.$user->role;
        $roleLabel = __('portal.'.$roleKey, [], $locale);
        if ($roleLabel === 'portal.'.$roleKey) {
            $roleLabel = str_replace('_', ' ', (string) $user->role);
        }

        $commissionRows = $rows($report['commissions'], fn (array $row) => [$row['label'], $row['entries'], $row['amount']]);
        $orderRows = $rows($report['orders'], fn (array $row) => [
            $row['number'],
            $row['status'],
            $row['total'],
            $row['paid'],
            $row['due'],
            collect($row['payments'] ?? [])->map(fn ($p) => ($p['channel'] ?? '').' '.$p['amount'].' ('.$p['status'].')')->implode('; ') ?: '—',
        ]);
        $teamRows = $rows($report['team_members'], fn (array $row) => [
            $row['name'],
            $row['dealer_code'] ?? '—',
            $row['role'],
            $row['phone'] ?? '—',
            $row['status'],
            '',
        ]);

        $html = '<html><head><style>
            body{font-family:freeserif,dejavusans,sans-serif;font-size:10pt;color:#111}
            h1{font-size:16pt;margin:0 0 4px} h2{font-size:12pt;margin:18px 0 6px}
            table{width:100%;border-collapse:collapse;margin-top:6px}
            th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;vertical-align:top}
            th{background:#f3f4f6} .muted{color:#555;font-size:9pt}
            .metrics td{border:0;padding:2px 8px 2px 0}
        </style></head><body>';
        $html .= '<h1>'.e($t('pdf_partner_full_report_title')).'</h1>';
        $html .= '<p class="muted">'.e($user->name).' · '.e((string) ($user->dealer_code ?: '—')).' · '.e($roleLabel).'</p>';
        $html .= '<table class="metrics"><tr>
            <td><b>'.e($t('report_col_ordered')).'</b><br>'.e($summary['ordered']).' ('.e((string) $summary['orders_count']).')</td>
            <td><b>'.e($t('report_col_paid')).'</b><br>'.e($summary['paid']).'</td>
            <td><b>'.e($t('report_col_due')).'</b><br>'.e($summary['due']).'</td>
            <td><b>'.e($t('report_commission_earned')).'</b><br>'.e($summary['commission']).'</td>
            <td><b>'.e($t('pdf_metric_team')).'</b><br>'.e((string) $summary['team_members']).' · '.e($summary['team_commission']).'</td>
        </tr></table>';
        $html .= '<h2>'.e($t('report_commission_by_head')).'</h2><table><thead><tr><th>'.e($t('report_col_head')).'</th><th>'.e($t('report_col_entries')).'</th><th>'.e($t('report_col_amount')).'</th></tr></thead><tbody>'.$commissionRows.'</tbody></table>';
        $html .= '<h2>'.e($t('report_order_payment_records')).'</h2><table><thead><tr><th>'.e($t('report_col_order')).'</th><th>'.e($t('col_status')).'</th><th>'.e($t('report_col_ordered')).'</th><th>'.e($t('report_col_paid')).'</th><th>'.e($t('report_col_due')).'</th><th>'.e($t('report_col_payments')).'</th></tr></thead><tbody>'.$orderRows.'</tbody></table>';
        $html .= '<h2>'.e($t('report_team_members')).'</h2><table><thead><tr><th>'.e($t('col_name')).'</th><th>'.e($t('pc_col_code')).'</th><th>'.e($t('partnership_type')).'</th><th>'.e($t('label_mobile')).'</th><th>'.e($t('col_status')).'</th><th></th></tr></thead><tbody>'.$teamRows.'</tbody></table>';
        $html .= '<p class="muted">'.e($t('pdf_partner_full_report_generated', ['when' => now('Asia/Dhaka')->format('d M Y H:i')])).'</p></body></html>';

        try {
            $pdf = new Mpdf([
                'tempDir' => $dir,
                'format' => 'A4',
                'default_font' => 'freeserif',
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
            ]);
            $pdf->SetTitle($t('pdf_partner_full_report_doc_title'));
            $pdf->SetHTMLFooter('<div style="text-align:right;font-size:8pt">'.e($t('pdf_partner_full_report_footer')).'</div>');
            $pdf->WriteHTML($html);
            $pdf->Output($path, Destination::FILE);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return response()->download(
            $path,
            'Madina-partner-report-'.$safeCode.'.pdf',
            ['Cache-Control' => 'private, no-store'],
        )->deleteFileAfterSend();
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function bounds(?string $from, ?string $to): array
    {
        $tz = (string) config('madina.business_timezone', 'Asia/Dhaka');
        $fromUtc = null;
        $toUtc = null;
        if (is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $fromUtc = CarbonImmutable::createFromFormat('Y-m-d', $from, $tz)->startOfDay()->utc();
        }
        if (is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $toUtc = CarbonImmutable::createFromFormat('Y-m-d', $to, $tz)->addDay()->startOfDay()->utc();
        }

        return [$fromUtc, $toUtc];
    }
}
