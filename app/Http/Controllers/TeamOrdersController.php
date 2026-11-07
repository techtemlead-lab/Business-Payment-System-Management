<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\TeamOrdersReport;
use App\Domain\Orders\OrderListFilter;
use App\Models\User;
use App\Support\Audit;
use App\Support\TableWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Team orders (টিমের অর্ডার): every confirmed order by the people under a partner,
 * with dates, member and status filters, totals and an Excel download. A partner
 * sees only their own team; office staff with the accounts or finance permission
 * can open any partner's team.
 */
class TeamOrdersController extends Controller
{
    public function __construct(private TeamOrdersReport $report) {}

    public function show(Request $request, ?User $partner = null): Response
    {
        $partner = $this->partner($request, $partner);
        $filters = $this->filters($request, $partner);
        $report = $this->report->for($partner, $filters);
        $staffView = $partner->id !== $request->user()->id;

        return Inertia::render('TeamOrders', [
            'partner' => $partner->only(['id', 'name', 'dealer_code', 'role', 'district']),
            'filters' => $filters,
            'statuses' => OrderListFilter::Confirmed,
            'orders' => $report['rows'],
            'totals' => $report['totals'],
            'members' => $report['members'],
            'staffView' => $staffView,
            'reportBase' => $staffView ? '/accounts/'.$partner->id.'/team-orders' : '/team-orders',
        ]);
    }

    /** The partner's own team orders as Excel. */
    public function download(Request $request): BinaryFileResponse
    {
        return $this->xlsx($request, null);
    }

    /** Any partner's team orders as Excel, for office staff. */
    public function downloadFor(Request $request, User $partner): BinaryFileResponse
    {
        return $this->xlsx($request, $partner);
    }

    private function xlsx(Request $request, ?User $partner): BinaryFileResponse
    {
        $partner = $this->partner($request, $partner);
        $filters = $this->filters($request, $partner);
        Audit::record($request->user()->id, 'report.exported', 'team-orders', ['partner_id' => $partner->id, ...$filters]);
        $label = fn (string $key): string => __('portal.'.$key);
        $columns = [
            ['key' => 'date', 'label' => $label('col_date')],
            ['key' => 'number', 'label' => $label('report_col_order')],
            ['key' => 'member_name', 'label' => $label('report_col_member')],
            ['key' => 'member_code', 'label' => $label('col_dealer_code')],
            ['key' => 'status_label', 'label' => $label('col_status')],
            ['key' => 'total', 'label' => $label('report_col_ordered'), 'type' => 'money', 'total' => true],
            ['key' => 'paid', 'label' => $label('report_col_paid'), 'type' => 'money', 'total' => true],
            ['key' => 'due', 'label' => $label('report_col_due'), 'type' => 'money', 'total' => true],
        ];
        $rows = (function () use ($partner, $filters) {
            foreach ($this->report->exportRows($partner, $filters) as $row) {
                yield [
                    ...$row,
                    'date' => CarbonImmutable::parse($row['created_at'])->timezone('Asia/Dhaka')->format('d M Y H:i'),
                    'member_code' => (string) ($row['member_code'] ?? ''),
                    'status_label' => __('portal.status_'.$row['status']),
                ];
            }
        })();
        $totals = $this->report->totals($partner, $filters);

        return TableWorkbook::download(
            'Madina-team-orders-'.$this->safeCode($partner).'-'.$filters['from'].'-'.$filters['to'].'.xlsx',
            $label('team_orders_title'),
            $columns,
            $rows,
            [
                [$label('col_partner'), $partner->name.' ('.($partner->dealer_code ?: '—').')'],
                [__('portal.ops_period'), $filters['from'].' '.__('portal.ops_through').' '.$filters['to']],
                [$label('team_orders_total_orders'), $totals['orders']],
                [$label('team_orders_buyers'), $totals['buyers']],
                [$label('report_col_ordered'), $totals['ordered']],
                [$label('report_col_paid'), $totals['paid']],
                [$label('report_col_due'), $totals['due']],
            ],
        );
    }

    /** The partner whose team is asked for, after the permission check. */
    private function partner(Request $request, ?User $partner): User
    {
        $viewer = $request->user();
        if ($partner === null) {
            abort_unless(in_array($viewer->role, ['dealer', 'sub_dealer'], true), 403);

            return $viewer;
        }
        abort_unless($viewer->staff() || $viewer->usingStaffWorkspace(), 403);
        abort_unless($viewer->allows('accounts') || $viewer->allows('finance'), 403);
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 404);

        return $partner;
    }

    /**
     * The chosen Dhaka dates (this month so far when none are given), member and status.
     * A member who is not in the partner's own downline is refused.
     *
     * @return array{from: string, to: string, member: ?int, status: ?string}
     */
    private function filters(Request $request, User $partner): array
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'member' => 'nullable|integer|min:1',
            'status' => ['nullable', 'string', Rule::in(OrderListFilter::Confirmed)],
        ]);
        $member = isset($data['member']) ? (int) $data['member'] : null;
        abort_if($member !== null && ! $this->report->inDownline($partner, $member), 404);
        $today = CarbonImmutable::now('Asia/Dhaka');

        return [
            'from' => $data['from'] ?? $today->startOfMonth()->toDateString(),
            'to' => $data['to'] ?? $today->toDateString(),
            'member' => $member,
            'status' => $data['status'] ?? null,
        ];
    }

    private function safeCode(User $partner): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($partner->dealer_code ?: $partner->id)) ?: (string) $partner->id;
    }
}
