<?php

namespace App\Domain\Inventory;

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
    public const Sections = ['orders', 'deliveries'];

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
