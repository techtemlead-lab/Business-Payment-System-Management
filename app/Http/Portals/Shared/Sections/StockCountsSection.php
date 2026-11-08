<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockCountService;
use App\Models\CompanyBranch;
use App\Models\StockCountLine;
use App\Models\StockCountSession;
use App\Models\User;

/**
 * Stock counts section: the branch's counts, one open count with its lines and variances,
 * and which branches are overdue. Follows the top-bar branch switcher.
 */
final class StockCountsSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'stock-counts') {
            return [];
        }
        $user = $context->user;
        $branchOperations = app(BranchOperations::class);
        $service = app(StockCountService::class);
        $visibleBranches = $branchOperations->visibleBranches($user);
        $scope = $branchOperations->scopeFor($user, $context->request, $visibleBranches);

        $sessions = StockCountSession::query()
            ->with(['branch:id,code,name', 'starter:id,name', 'submitter:id,name', 'decider:id,name'])
            ->withCount([
                'lines as counted_lines' => fn ($lines) => $lines->whereNotNull('counted_quantity'),
                'lines as variance_lines' => fn ($lines) => $lines->whereNotNull('counted_quantity')->whereColumn('counted_quantity', '!=', 'expected_quantity'),
            ])
            ->orderByDesc('id');
        $scope->restrict($sessions, 'branch_id');

        $selected = StockCountSession::query()->with(['branch:id,code,name', 'lines.product:id,sku,name']);
        $scope->restrict($selected, 'branch_id');
        $requestedId = $context->request->integer('session');
        $selected = $requestedId > 0
            ? $selected->whereKey($requestedId)->first()
            : $selected->whereIn('status', [StockCountSession::StatusCounting, StockCountSession::StatusSubmitted])->orderByDesc('id')->first();

        return ['stock_counts' => [
            'branch_scope' => $scope->chip(),
            'can' => [
                'create' => $user->canAct('inventory', 'create'),
                'edit' => $user->canAct('inventory', 'edit'),
                'approve' => $user->canAct('inventory', 'approve'),
            ],
            'sessions' => $sessions->paginate(20)->withQueryString()
                ->through(fn (StockCountSession $session): array => $this->sessionRow($session) + [
                    'counted_lines' => (int) $session->counted_lines,
                    'variance_lines' => (int) $session->variance_lines,
                ]),
            'selected' => $selected ? $this->selectedRow($selected, $user, $service) : null,
            'branches' => $visibleBranches
                ->filter(fn (CompanyBranch $branch): bool => (bool) $branch->is_active && (bool) $branch->is_stock_hub)
                ->map(fn (CompanyBranch $branch): array => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name])
                ->values(),
            'overdue_days' => StockCountService::OverdueDays,
            'overdue' => $service->overdueBranches()
                ->filter(fn (CompanyBranch $branch): bool => $visibleBranches->contains('id', $branch->id))
                ->map(fn (CompanyBranch $branch): array => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name])
                ->values(),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRow(StockCountSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status,
            'branch_code' => $session->branch?->code,
            'branch_name' => $session->branch?->name,
            'started_by_name' => $session->starter?->name,
            'submitted_by_name' => $session->submitter?->name,
            'decided_by_name' => $session->decider?->name,
            'decision_note' => $session->decision_note,
            'created_at' => $session->created_at,
            'decided_at' => $session->decided_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedRow(StockCountSession $session, User $user, StockCountService $service): array
    {
        $counted = $session->lines->whereNotNull('counted_quantity');

        return $this->sessionRow($session) + [
            'can_decide' => $session->status === StockCountSession::StatusSubmitted
                && $user->canAct('inventory', 'approve')
                && ! $service->tookPartInCount($user, $session, $counted),
            'can_decide_alone' => $session->status === StockCountSession::StatusSubmitted && $service->canDecideAlone($user, $session, $counted),
            'lines' => $session->lines->sortBy('id')->map(fn (StockCountLine $line): array => [
                'id' => $line->id,
                'sku' => $line->product?->sku,
                'name' => $line->product?->name,
                'expected' => $line->expected_quantity,
                'counted' => $line->counted_quantity,
                'variance' => $line->variance(),
            ])->values(),
        ];
    }
}
