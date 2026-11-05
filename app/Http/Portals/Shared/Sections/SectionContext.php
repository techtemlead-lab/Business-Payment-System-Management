<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Pricing\ProductPricing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * What every workspace section needs to build its props. The workspace controller
 * fills it once, after the section was allowed and the filters were validated.
 */
final readonly class SectionContext
{
    /**
     * @param  bool  $isStaff  Manage or sadmin portal, or a partner working in the staff workspace.
     * @param  string  $catalogPortal  Portal whose section list was checked for this request.
     * @param  Builder<Order>  $orders  Orders this viewer may see. Clone it before adding conditions.
     */
    public function __construct(
        public Request $request,
        public User $user,
        public string $portal,
        public string $section,
        public bool $isStaff,
        public bool $usingStaffWorkspace,
        public string $catalogPortal,
        public Builder $orders,
        public ProductPricing $pricing,
    ) {}
}
