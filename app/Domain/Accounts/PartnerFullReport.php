<?php

namespace App\Domain\Accounts;

use App\Domain\Orders\DeliveredMoney;
use App\Domain\Orders\OrderListFilter;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\LocalDigits;
use App\Support\Money;
use App\Support\PaymentChannel;
use App\Support\PdfFonts;
use App\Support\PhoneIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PartnerFullReport
{
    public function __construct(private PartnerMoneyBook $moneyBook) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, ?string $from = null, ?string $to = null): array
    {
        [$fromUtc, $toUtc] = $this->bounds($from, $to);
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['submitted', 'held', 'approved', 'partially_dispatched', 'dispatched', 'delivered', 'partly_delivered'])
            ->when($fromUtc, fn ($query) => $query->where('created_at', '>=', $fromUtc))
            ->when($toUtc, fn ($query) => $query->where('created_at', '<', $toUtc))
            ->orderByDesc('created_at')
            ->select(['id', 'number', 'status', 'total', 'paid', 'due_credit', 'created_at'])
            ->selectRaw(DeliveredMoney::valueSql().' AS delivered_value, '.DeliveredMoney::paidSql().' AS delivered_paid')
            ->get();

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
            $ordered += Money::cents((string) $order->getAttribute('delivered_value'));
            $paid += Money::cents((string) $order->getAttribute('delivered_paid'));
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
                'total' => Money::decimal(Money::cents((string) $order->getAttribute('delivered_value'))),
                'paid' => Money::decimal(Money::cents((string) $order->getAttribute('delivered_paid'))),
                'due' => $order->outstanding(),
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'payments' => $orderClaims,
            ];
        }

        $earned = $this->moneyBook->earnedByHead((int) $user->id, $fromUtc, $toUtc);
        $byCategory = array_map(fn (array $row): array => [
            'category' => $row['head'],
            'label' => PartnerMoneyBook::headWord($row['head'], $user->preferredLocale()),
            'amount' => Money::decimal($row['amount']),
            'entries' => $row['entries'],
        ], $earned);

        $teamIds = ReferralTreePath::query()
            ->where('ancestor_id', $user->id)
            ->where('depth', '>', 0)
            ->pluck('descendant_id');

        $teamMembers = $teamIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $teamIds)->orderBy('name')->get(['id', 'name', 'dealer_code', 'role', 'phone', 'status', 'sponsor_id']);

        $teamOrders = $teamIds->isEmpty()
            ? collect()
            : Order::query()
                ->whereIn('user_id', $teamIds)
                ->whereIn('status', OrderListFilter::Confirmed)
                ->when($fromUtc, fn ($query) => $query->where('created_at', '>=', $fromUtc))
                ->when($toUtc, fn ($query) => $query->where('created_at', '<', $toUtc))
                ->latest()
                ->limit(40)
                ->select(['id', 'user_id', 'number', 'status', 'total', 'paid', 'due_credit', 'created_at'])
                ->selectRaw(DeliveredMoney::valueSql().' AS delivered_value, '.DeliveredMoney::paidSql().' AS delivered_paid')
                ->get();

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
                'total' => Money::decimal(Money::cents((string) $order->getAttribute('delivered_value'))),
                'paid' => Money::decimal(Money::cents((string) $order->getAttribute('delivered_paid'))),
                'due' => $order->outstanding(),
                'created_at' => optional($order->created_at)?->toIso8601String(),
            ];
        })->values()->all();

        $teamCommission = Money::decimal($this->moneyBook->earnedTotal(
            ReferralTreePath::query()->where('ancestor_id', $user->id)->where('depth', '>', 0)->select('descendant_id')->toBase(),
            $fromUtc,
            $toUtc,
        ));

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
                'commission' => Money::decimal(array_sum(array_column($earned, 'amount'))),
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
                // Full numbers only for directly referred members; the rest show the last four digits.
                'phone' => PhoneIdentity::forDownlineViewer($member->phone, (int) $member->id, $member->sponsor_id !== null ? (int) $member->sponsor_id : null, (int) $user->id),
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
        // Amounts and counts follow the reader's language; codes such as DL-1001 stay as they are.
        $localize = static fn (string $value): string => preg_match('/^[\d.,]+$/', $value) === 1 ? LocalDigits::text($value, $locale) : $value;
        $rows = static function (array $items, callable $cells) use ($none, $localize): string {
            if ($items === []) {
                return '<tr><td colspan="6">'.e($none).'</td></tr>';
            }
            $html = '';
            foreach ($items as $item) {
                $html .= '<tr>';
                foreach ($cells($item) as $cell) {
                    $html .= '<td>'.e($localize((string) $cell)).'</td>';
                }
                $html .= '</tr>';
            }

            return $html;
        };

        $roleLabel = self::roleWord((string) $user->role, $locale);

        $commissionRows = $rows($report['commissions'], fn (array $row) => [$row['label'], $row['entries'], $row['amount']]);
        $orderRows = $rows($report['orders'], fn (array $row) => [
            $row['number'],
            self::statusWord((string) $row['status'], $locale),
            $row['total'],
            $row['paid'],
            $row['due'],
            collect($row['payments'] ?? [])->map(fn ($p) => PaymentChannel::word((string) ($p['channel'] ?? ''), $locale).' '.$localize((string) $p['amount']).' ('.self::statusWord((string) $p['status'], $locale).')')->implode('; ') ?: '—',
        ]);
        $teamRows = $rows($report['team_members'], fn (array $row) => [
            $row['name'],
            $row['dealer_code'] ?? '—',
            self::roleWord((string) $row['role'], $locale),
            $row['phone'] ?? '—',
            self::statusWord((string) $row['status'], $locale),
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
            <td><b>'.e($t('report_col_ordered')).'</b><br>'.e($localize($summary['ordered'])).' ('.e($localize((string) $summary['orders_count'])).')</td>
            <td><b>'.e($t('pdf_metric_paid_for_delivered')).'</b><br>'.e($localize($summary['paid'])).'</td>
            <td><b>'.e($t('pdf_metric_still_to_pay')).'</b><br>'.e($localize($summary['due'])).'</td>
            <td><b>'.e($t('report_commission_earned')).'</b><br>'.e($localize($summary['commission'])).'</td>
            <td><b>'.e($t('pdf_metric_team')).'</b><br>'.e($localize((string) $summary['team_members'])).' · '.e($localize($summary['team_commission'])).'</td>
        </tr></table>';
        $html .= '<h2>'.e($t('report_commission_by_head')).'</h2><table><thead><tr><th>'.e($t('report_col_head')).'</th><th>'.e($t('report_col_entries')).'</th><th>'.e($t('report_col_amount')).'</th></tr></thead><tbody>'.$commissionRows.'</tbody></table>';
        $html .= '<h2>'.e($t('report_order_payment_records')).'</h2><table><thead><tr><th>'.e($t('report_col_order')).'</th><th>'.e($t('col_status')).'</th><th>'.e($t('report_col_ordered')).'</th><th>'.e($t('report_col_paid')).'</th><th>'.e($t('report_col_due')).'</th><th>'.e($t('report_col_payments')).'</th></tr></thead><tbody>'.$orderRows.'</tbody></table>';
        $html .= '<h2>'.e($t('report_team_members')).'</h2><table><thead><tr><th>'.e($t('col_name')).'</th><th>'.e($t('pc_col_code')).'</th><th>'.e($t('partnership_type')).'</th><th>'.e($t('label_mobile')).'</th><th>'.e($t('col_status')).'</th><th></th></tr></thead><tbody>'.$teamRows.'</tbody></table>';
        $html .= '<p class="muted">'.e($t('pdf_partner_full_report_generated', ['when' => LocalDigits::text(now('Asia/Dhaka')->locale($locale)->translatedFormat('d M Y H:i'), $locale)])).'</p></body></html>';

        try {
            $pdf = new Mpdf([
                'tempDir' => $dir,
                'format' => 'A4',
                ...PdfFonts::options(),
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

    /** An order, payment or account status as people read it. */
    public static function statusWord(string $status, string $locale): string
    {
        $key = 'portal.status_'.$status;
        $word = __($key, [], $locale);

        return $word === $key ? Str::headline($status) : $word;
    }

    /** A partner type as people read it. */
    public static function roleWord(string $role, string $locale): string
    {
        $key = 'portal.acct_role_'.$role;
        $word = __($key, [], $locale);

        return $word === $key ? Str::headline($role) : $word;
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
