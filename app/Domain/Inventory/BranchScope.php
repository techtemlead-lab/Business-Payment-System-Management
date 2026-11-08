<?php

namespace App\Domain\Inventory;

use App\Domain\Support\ChatRouting;
use App\Models\CompanyBranch;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The branches one staff page shows, as picked in the top-bar branch switcher
 * ({@see BranchOperations::scopeFor()}). "All branches" is only ever given to
 * the owner and staff who may view every branch; everyone else gets the one
 * branch they work in, or nothing when they have no branch.
 */
final readonly class BranchScope
{
    /**
     * Staff workspace sections that follow the top-bar branch switcher.
     *
     * @var list<string>
     */
    public const Sections = ['orders', 'deliveries', 'payments', 'withdrawals', 'payouts-hub', 'reports', 'overview', 'top-sellers', 'partner-scorecard', 'accounts', 'sub-dealers', 'employees'];

    /**
     * @param  list<int>  $branchIds  Branches the page shows; ignored when $allBranches is true.
     */
    public function __construct(
        public bool $allBranches,
        public array $branchIds,
        public ?CompanyBranch $branch = null,
    ) {}

    public static function covers(string $section): bool
    {
        return in_array($section, self::Sections, true);
    }

    /**
     * Keep only the rows of the scoped branches. Rows with no branch (NULL) are
     * kept only on "All branches", so branch staff never see them.
     *
     * @template TBuilder of EloquentBuilder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function restrict(EloquentBuilder|QueryBuilder $query, string $branchColumn): EloquentBuilder|QueryBuilder
    {
        if ($this->allBranches) {
            return $query;
        }
        if ($this->branchIds === []) {
            $query->whereRaw('1 = 0');

            return $query;
        }
        $query->whereIn($branchColumn, $this->branchIds);

        return $query;
    }

    /**
     * Keep only the orders of the scoped branches. An order gets its branch when it is
     * sent, and until then the head office is the branch that will send it, so the head
     * office also lists orders with no branch yet. Every other branch keeps the rule
     * of {@see restrict()}.
     *
     * @template TBuilder of EloquentBuilder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function restrictOrders(EloquentBuilder|QueryBuilder $query, string $branchColumn = 'orders.fulfillment_branch_id'): EloquentBuilder|QueryBuilder
    {
        if ($this->headOfficeOnScreen() === null) {
            return $this->restrict($query, $branchColumn);
        }
        $query->where(function ($orders) use ($branchColumn): void {
            $orders->whereIn($branchColumn, $this->branchIds)->orWhereNull($branchColumn);
        });

        return $query;
    }

    /**
     * Keep only partners of the scoped branches. A partner belongs to the branch saved
     * on the account, or else the active branch nearest their place code (the mapping
     * partner chat uses too, {@see ChatRouting::partnerBranchSql()}).
     * Partners with no branch are kept on "All branches" and on the head office.
     *
     * @template TBuilder of EloquentBuilder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function restrictPartners(EloquentBuilder|QueryBuilder $query, string $usersTable = 'users'): EloquentBuilder|QueryBuilder
    {
        if ($this->allBranches) {
            return $query;
        }
        if ($this->branchIds === []) {
            $query->whereRaw('1 = 0');

            return $query;
        }
        $query->whereRaw($this->partnerBranchCondition($usersTable), $this->branchIds);

        return $query;
    }

    /**
     * SQL that is true for partners of the scoped branches. Bind {@see $branchIds} to it.
     * Only for a scope that has at least one branch.
     */
    public function partnerBranchCondition(string $usersTable = 'users'): string
    {
        $partnerBranch = app(ChatRouting::class)->partnerBranchSql($usersTable);
        $headOffice = $this->headOfficeOnScreen();
        if ($headOffice !== null) {
            $partnerBranch = 'COALESCE('.$partnerBranch.', '.(int) $headOffice->id.')';
        }

        return $partnerBranch.' IN ('.implode(', ', array_fill(0, count($this->branchIds), '?')).')';
    }

    /** True when the head office is the branch this page shows. */
    public function showsHeadOffice(): bool
    {
        return $this->headOfficeOnScreen() !== null;
    }

    /** The head office, when it is the branch this page shows. */
    private function headOfficeOnScreen(): ?CompanyBranch
    {
        return ! $this->allBranches && $this->branch?->is_head_office ? $this->branch : null;
    }

    /**
     * The "Showing: ..." chip on top of a scoped page.
     *
     * @return array{view: 'all'|'branch', id: int|null, code: string|null, name: string|null}
     */
    public function chip(): array
    {
        if ($this->allBranches) {
            return ['view' => 'all', 'id' => null, 'code' => 'ALL', 'name' => __('portal.branch_view_all')];
        }

        return [
            'view' => 'branch',
            'id' => $this->branch?->id,
            'code' => $this->branch?->code,
            'name' => $this->branch?->name,
        ];
    }
}
