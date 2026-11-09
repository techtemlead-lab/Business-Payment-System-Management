<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockMovementList;
use App\Domain\Orders\OrderListFilter;
use App\Support\Audit;
use App\Support\TableWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel download of the stock movement log with the filters on screen,
 * for staff with the inventory permission.
 */
class StockMovementExportController extends Controller
{
    public function __invoke(Request $request, StockMovementList $list): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->staff() || $user->usingStaffWorkspace(), 403);
        abort_unless($user->allows('inventory'), 403);
        $request->validate(StockMovementList::rules());
        $filters = StockMovementList::filters($request);
        $scope = StockMovementList::scope($user, $request, app(BranchOperations::class)->preferredBranch($user));
        $label = fn (string $key): string => __('portal.'.$key);
        $columns = [
            ['key' => 'date', 'label' => $label('col_date')],
            ['key' => 'product', 'label' => $label('col_product')],
            ['key' => 'sku', 'label' => $label('col_sku')],
            ['key' => 'branch', 'label' => $label('col_branch')],
            ['key' => 'kind', 'label' => $label('col_type')],
            ['key' => 'units_in', 'label' => $label('inv_col_units_in'), 'type' => 'int', 'total' => true],
            ['key' => 'units_out', 'label' => $label('inv_col_units_out'), 'type' => 'int', 'total' => true],
            ['key' => 'reason', 'label' => $label('inv_col_reason_order')],
            ['key' => 'actor', 'label' => $label('audit_actor')],
        ];
        Audit::record($user->id, 'report.exported', 'stock-movements', array_filter($filters, fn ($value): bool => $value !== '' && $value !== 0 && $value !== null));

        return TableWorkbook::download(
            'Madina-stock-movements-'.now('Asia/Dhaka')->format('Y-m-d').'.xlsx',
            $label('inv_export_title'),
            $columns,
            $list->exportRows($filters, $scope),
            [[__('portal.acct_export_filters'), $this->describe($filters, $scope)]],
        );
    }

    /**
     * The chosen filters in words for the summary sheet.
     *
     * @param  array{q: string, period: string, from: ?string, to: ?string, branch_id: int, product_id: int, movement: string}  $filters
     */
    private function describe(array $filters, BranchScope $scope): string
    {
        $parts = [];
        $range = OrderListFilter::createdRange($filters['period'], $filters['from'], $filters['to']);
        if ($range !== null) {
            $parts[] = __('portal.ops_period').': '.$range['from']->timezone('Asia/Dhaka')->format('d M Y').' '.__('portal.ops_through').' '.$range['until']->timezone('Asia/Dhaka')->format('d M Y');
        }
        if (! $scope->allBranches && $scope->branch !== null) {
            $parts[] = __('portal.col_branch').': '.$scope->branch->name;
        } elseif ($scope->allBranches && $filters['branch_id'] > 0) {
            $parts[] = __('portal.col_branch').': '.(DB::table('company_branches')->where('id', $filters['branch_id'])->value('name') ?? $filters['branch_id']);
        }
        if ($filters['product_id'] > 0) {
            $parts[] = __('portal.col_product').': '.(DB::table('products')->where('id', $filters['product_id'])->value('name') ?? $filters['product_id']);
        }
        if ($filters['movement'] !== '') {
            $parts[] = __('portal.col_type').': '.StockMovementList::kindLabel($filters['movement']);
        }
        if ($filters['q'] !== '') {
            $parts[] = __('portal.search').': '.$filters['q'];
        }

        return $parts === [] ? __('portal.inv_export_all') : implode(' · ', $parts);
    }
}
