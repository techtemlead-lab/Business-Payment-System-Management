<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockLotReports;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Batches and expiry: near-expiry and expired lots, batch recall, branch transfers and the
 * reorder list. Every list follows the top-bar branch, the same as the other staff pages.
 */
final class StockLotsSection implements WorkspaceSection
{
    private const Tabs = ['near', 'recall', 'transfers', 'reorder', 'hold', 'details'];

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'stock-lots') {
            return [];
        }
        $request = $context->request;
        $reports = app(StockLotReports::class);
        $branchOps = app(BranchOperations::class);
        $visible = $branchOps->visibleBranches($context->user);
        $scope = $branchOps->scopeFor($context->user, $request, $visible);
        $tab = in_array($request->query('tab'), self::Tabs, true) ? (string) $request->query('tab') : 'near';
        $savedDays = $reports->nearExpiryDays();
        $days = in_array($request->integer('days'), StockLotReports::NearExpiryChoices, true) ? $request->integer('days') : $savedDays;

        $payload = [
            'tab' => $tab,
            'branch_scope' => $scope->chip(),
            'days' => $days,
            'saved_days' => $savedDays,
            'day_choices' => StockLotReports::NearExpiryChoices,
            'cover_days' => StockLotReports::ReorderCoverDays,
            'average_days' => StockLotReports::ReorderAverageDays,
            'missing_details_count' => $reports->missingDetailsCount($scope),
        ];
        $payload += match ($tab) {
            'recall' => ['recall' => $reports->recall($scope, (string) $request->query('batch', ''))],
            'transfers' => $this->transfers($scope, $visible),
            'reorder' => ['reorder' => $reports->reorderSuggestions($scope)],
            'details' => ['missing_details' => $reports->lotsMissingDetails($scope)],
            'hold' => [
                'held' => $reports->heldLots($scope),
                'hold_search' => (string) $request->query('q', ''),
                'hold_candidates' => $reports->holdCandidates($scope, (string) $request->query('q', '')),
            ],
            default => ['expiry' => $reports->expiryLists($scope, $days)],
        };

        return ['stock_lots' => $payload];
    }

    /**
     * @param  Collection<int, CompanyBranch>  $visible
     * @return array<string, mixed>
     */
    private function transfers(BranchScope $scope, Collection $visible): array
    {
        $query = StockTransfer::query()
            ->with(['product:id,name,sku', 'sourceBranch:id,code,name', 'destinationBranch:id,code,name'])
            ->orderByDesc('id')
            ->limit(100);
        if (! $scope->allBranches) {
            $query->where(function (Builder $transfers) use ($scope): void {
                $transfers->whereIn('source_branch_id', $scope->branchIds)->orWhereIn('destination_branch_id', $scope->branchIds);
            });
        }

        return [
            'transfers' => $query->get()->map(fn (StockTransfer $transfer): array => [
                'id' => $transfer->id,
                'number' => $transfer->number,
                'status' => $transfer->status,
                'quantity' => $transfer->quantity,
                'note' => $transfer->note,
                'product' => $transfer->product?->name,
                'source' => $transfer->sourceBranch?->code,
                'destination' => $transfer->destinationBranch?->code,
                'created_at' => $transfer->created_at?->toIso8601String(),
            ])->all(),
            'product_options' => Product::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'sku'])
                ->map(fn (Product $product): array => ['value' => (string) $product->id, 'label' => $product->name.' · '.$product->sku])->all(),
            'branch_options' => $visible->filter(fn (CompanyBranch $branch): bool => (bool) $branch->is_active && (bool) $branch->is_stock_hub)
                ->map(fn (CompanyBranch $branch): array => ['value' => (string) $branch->id, 'label' => $branch->name.' · '.$branch->code])->values()->all(),
        ];
    }
}
