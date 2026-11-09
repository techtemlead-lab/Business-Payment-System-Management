<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\BranchPerformance;
use App\Support\Audit;
use App\Support\TableWorkbook;
use Generator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel download of every confirmed order behind the branch performance screen,
 * for the same period and branch and the same branches the reader may see, with
 * totals for the whole range. For staff with the reports permission.
 */
class BranchPerformanceExportController extends Controller
{
    public function __invoke(Request $request, BranchPerformance $performance): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->staff() || $user->usingStaffWorkspace(), 403);
        abort_unless($user->allows('reports'), 403);
        $filters = $request->validate([
            'period' => 'nullable|in:today,month,custom',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'branch_id' => 'nullable|integer|min:1',
        ]);
        ['period' => $period, 'branch_id' => $branchId, 'branches' => $branches, 'can_view_all_branches' => $canViewAllBranches] = $performance->reportScope($user, $filters);
        $label = fn (string $key): string => __('portal.'.$key);
        $columns = [
            ['key' => 'number', 'label' => $label('col_order')],
            ['key' => 'partner', 'label' => $label('col_partner')],
            ['key' => 'branch', 'label' => $label('col_branch')],
            ['key' => 'status', 'label' => $label('col_status')],
            ['key' => 'date', 'label' => $label('col_date')],
            ['key' => 'total', 'label' => $label('branch_perf_order_value'), 'type' => 'money', 'total' => true],
            ['key' => 'paid', 'label' => $label('branch_perf_income'), 'type' => 'money', 'total' => true],
            ['key' => 'outstanding', 'label' => $label('branch_perf_outstanding'), 'type' => 'money', 'total' => true],
            ['key' => 'estimated_profit', 'label' => $label('branch_perf_profit'), 'type' => 'money', 'total' => true],
        ];
        Audit::record($user->id, 'report.exported', 'branch-performance', ['from' => $period['from'], 'to' => $period['to'], 'branch_id' => $branchId]);
        $branchName = match (true) {
            $branchId !== null => $branches[$branchId]->name,
            $canViewAllBranches => $label('branch_perf_all_branches'),
            default => $branches->pluck('name')->implode(', '),
        };

        return TableWorkbook::download(
            'Madina-branch-performance-'.$period['from'].'-'.$period['to'].'.xlsx',
            $label('branch_perf_title'),
            $columns,
            $this->exportRows($performance, $user, $filters),
            [
                [__('portal.ops_period'), $period['from'].' '.__('portal.ops_through').' '.$period['to']],
                [$label('col_branch'), $branchName],
                [$label('branch_perf_export_note_label'), $label('branch_perf_export_note')],
            ],
        );
    }

    /**
     * One row per order, with the partner, branch, status and date as shown on screen.
     *
     * @param  array<string, mixed>  $filters
     * @return Generator<int, array<string, mixed>>
     */
    private function exportRows(BranchPerformance $performance, mixed $user, array $filters): Generator
    {
        foreach ($performance->orderRows($user, $filters) as $order) {
            $statusKey = 'portal.status_'.$order['status'];
            $status = __($statusKey);
            yield [
                ...$order,
                'partner' => trim(($order['partner_name'] ?? '').($order['dealer_code'] ? ' ('.$order['dealer_code'].')' : '')),
                'branch' => $order['branch_code'] ? $order['branch_name'].' · '.$order['branch_code'] : $order['branch_name'],
                'status' => $status === $statusKey ? ucfirst((string) $order['status']) : $status,
                'date' => substr((string) $order['created_at'], 0, 10),
            ];
        }
    }
}
