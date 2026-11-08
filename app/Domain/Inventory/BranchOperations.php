<?php

namespace App\Domain\Inventory;

use App\Domain\Geography\PlaceTree;
use App\Models\BranchProductStock;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class BranchOperations
{
    public const KindProductIn = 'product_in';

    public const KindProductOut = 'product_out';

    public const SessionView = 'branch_view';

    /** @var list<string> */
    public const ProductInReasonCodes = [
        'supplier_receipt',
        'return',
        'opening',
        'transfer_in',
        'correction',
        'other',
    ];

    /** @var list<string> */
    public const ProductOutReasonCodes = [
        'damage',
        'write_off',
        'unofficial_out',
        'expired',
        'transfer_out',
        'correction',
        'other',
    ];

    public function __construct(private StockNotifications $notifications) {}

    /**
     * @param  array{
     *   code:string,name:string,division?:?string,district?:?string,upazila?:?string,
     *   address?:?string,phone?:?string,place_code_id?:?int,latitude?:?float,longitude?:?float,
     *   is_active?:bool,is_delivery_point?:bool,is_stock_hub?:bool,notes?:?string
     * }  $data
     */
    public function saveBranch(User $actor, array $data, ?CompanyBranch $branch = null): CompanyBranch
    {
        abort_unless($actor->owner() || $actor->allows('inventory') || $actor->allows('accounts'), 403);

        return DB::transaction(function () use ($actor, $data, $branch): CompanyBranch {
            $code = strtoupper(trim($data['code']));
            $exists = CompanyBranch::query()
                ->where('code', $code)
                ->when($branch, fn ($q) => $q->where('id', '!=', $branch->id))
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages(['code' => __('portal.err_branch_code_taken')]);
            }

            $place = $this->branchPlace($data, $branch);
            $names = app(PlaceTree::class)->namesDownTo($place);
            $payload = [
                'code' => $code,
                'name' => trim($data['name']),
                'division' => $names['division'] ?? null,
                'district' => $names['district'] ?? null,
                'upazila' => $names['upazila'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'place_code_id' => $place->id,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'is_delivery_point' => array_key_exists('is_delivery_point', $data) ? (bool) $data['is_delivery_point'] : true,
                'is_stock_hub' => array_key_exists('is_stock_hub', $data) ? (bool) $data['is_stock_hub'] : true,
                'notes' => $data['notes'] ?? null,
            ];

            if ($branch) {
                $branch->fill($payload)->save();
            } else {
                $payload['created_by'] = $actor->id;
                $branch = CompanyBranch::query()->create($payload);
            }

            Audit::record($actor->id, $branch->wasRecentlyCreated ? 'branch.created' : 'branch.updated', (string) $branch->id, [
                'code' => $branch->code,
                'name' => $branch->name,
            ]);

            return $branch->refresh();
        });
    }

    /**
     * Every branch must sit on an active place code. A typed code wins; an API may send the
     * place code id; an edit that sends neither keeps the branch's current code.
     *
     * @param  array<string, mixed>  $data
     */
    private function branchPlace(array $data, ?CompanyBranch $branch): PlaceCode
    {
        $typed = trim((string) ($data['place_code'] ?? ''));
        if ($typed !== '') {
            return PlaceTree::requirePlace($typed);
        }
        $placeId = $data['place_code_id'] ?? (array_key_exists('place_code_id', $data) ? null : $branch?->place_code_id);
        $existing = $placeId !== null ? PlaceCode::query()->find((int) $placeId) : null;

        return PlaceTree::requirePlace($existing?->code);
    }

    /**
     * @param  array{user_id:int,duty?:string,is_primary?:bool}  $data
     */
    public function assignStaff(User $actor, CompanyBranch $branch, array $data): BranchStaffAssignment
    {
        abort_unless($actor->owner() || $actor->allows('accounts') || $actor->allows('inventory'), 403);
        $user = User::query()->whereKey($data['user_id'])->firstOrFail();
        abort_unless($user->staff() || $user->owner(), 422, __('portal.err_branch_staff_only'));

        return DB::transaction(function () use ($actor, $branch, $user, $data): BranchStaffAssignment {
            $duty = $data['duty'] ?? 'storekeeper';
            if (! in_array($duty, BranchStaffAssignment::Duties, true)) {
                throw ValidationException::withMessages(['duty' => __('portal.err_branch_duty')]);
            }
            $isPrimary = (bool) ($data['is_primary'] ?? false);
            if ($isPrimary) {
                BranchStaffAssignment::query()->where('user_id', $user->id)->update(['is_primary' => false]);
            }

            /** @var BranchStaffAssignment $assignment */
            $assignment = BranchStaffAssignment::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'user_id' => $user->id],
                ['duty' => $duty, 'is_primary' => $isPrimary]
            );

            if ($user->active_branch_id === null || $isPrimary) {
                $user->forceFill(['active_branch_id' => $branch->id])->save();
            }

            Audit::record($actor->id, 'branch.staff_assigned', (string) $branch->id, [
                'user_id' => $user->id,
                'duty' => $duty,
                'is_primary' => $isPrimary,
            ]);

            return $assignment->refresh();
        });
    }

    public function removeStaff(User $actor, CompanyBranch $branch, User $staff): void
    {
        abort_unless($actor->owner() || $actor->allows('accounts') || $actor->allows('inventory'), 403);
        DB::transaction(function () use ($actor, $branch, $staff): void {
            BranchStaffAssignment::query()
                ->where('branch_id', $branch->id)
                ->where('user_id', $staff->id)
                ->delete();
            if ((int) $staff->active_branch_id === (int) $branch->id) {
                $fallback = BranchStaffAssignment::query()
                    ->where('user_id', $staff->id)
                    ->orderByDesc('is_primary')
                    ->value('branch_id');
                $staff->forceFill(['active_branch_id' => $fallback])->save();
            }
            Audit::record($actor->id, 'branch.staff_removed', (string) $branch->id, ['user_id' => $staff->id]);
        });
    }

    public function deleteBranch(User $actor, CompanyBranch $branch): void
    {
        abort_unless($actor->owner() || $actor->allows('accounts') || $actor->allows('inventory'), 403);
        if ($branch->is_head_office) {
            throw ValidationException::withMessages(['branch' => __('portal.err_branch_head_office')]);
        }

        $hasStock = BranchProductStock::query()
            ->where('branch_id', $branch->id)
            ->where('quantity', '>', 0)
            ->exists();
        $hasMovements = DB::table('stock_movements')->where('branch_id', $branch->id)->exists();
        $hasOrders = DB::table('orders')->where('fulfillment_branch_id', $branch->id)->exists();
        $hasShipments = Schema::hasTable('shipments')
            && Schema::hasColumn('shipments', 'branch_id')
            && DB::table('shipments')->where('branch_id', $branch->id)->exists();
        if ($hasStock || $hasMovements || $hasOrders || $hasShipments) {
            throw ValidationException::withMessages(['branch' => __('portal.err_branch_delete_history')]);
        }

        DB::transaction(function () use ($actor, $branch): void {
            Audit::record($actor->id, 'branch.deleted', (string) $branch->id, [
                'code' => $branch->code,
                'name' => $branch->name,
            ]);
            User::query()->where('active_branch_id', $branch->id)->update(['active_branch_id' => null]);
            $branch->delete();
        });
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    public function expandStockAcrossBranches(iterable $products): array
    {
        $products = collect($products)->map(fn (mixed $product): array => (array) $product)->all();
        $ids = array_values(array_filter(array_map(fn (array $product): int => (int) ($product['id'] ?? 0), $products)));
        $lines = BranchProductStock::query()
            ->with('branch:id,name,code')
            ->whereIn('product_id', $ids)
            ->orderBy('branch_id')
            ->get()
            ->groupBy('product_id');
        $rows = [];
        foreach ($products as $product) {
            $company = (int) ($product['stock'] ?? 0);
            $group = $lines->get($product['id'], collect());
            if ($group->isEmpty()) {
                $product['branch_stock'] = 0;
                $product['company_stock'] = $company;
                $product['branch_name'] = null;
                $product['row_key'] = (string) $product['id'];
                $rows[] = $product;

                continue;
            }
            foreach ($group as $line) {
                $copy = $product;
                $copy['branch_id'] = $line->branch_id;
                $copy['branch_name'] = $line->branch?->name;
                $copy['branch_code'] = $line->branch?->code;
                $copy['branch_stock'] = (int) $line->quantity;
                $copy['company_stock'] = $company;
                $copy['row_key'] = $product['id'].'-'.$line->branch_id;
                $rows[] = $copy;
            }
        }

        return $rows;
    }

    public function selectActiveBranch(User $actor, CompanyBranch $branch): User
    {
        abort_unless($actor->staff() || $actor->owner(), 403);
        abort_unless($branch->is_active, 422, __('portal.err_branch_inactive'));

        // Picking the branch to look at is a viewing choice; stock writes still check canManageAllBranches().
        if (! $this->canViewAllBranches($actor)) {
            $assigned = BranchStaffAssignment::query()
                ->where('branch_id', $branch->id)
                ->where('user_id', $actor->id)
                ->exists();
            abort_unless($assigned, 403);
        }

        $actor->forceFill(['active_branch_id' => $branch->id])->save();
        Audit::record($actor->id, 'branch.selected', (string) $branch->id, ['code' => $branch->code]);

        return $actor->refresh();
    }

    /**
     * Adjust stock at a branch and keep products.stock as the company-wide rollup.
     *
     * Explicit kinds `product_in` / `product_out` take a positive quantity and map the sign.
     * Legacy callers may omit kind and pass a signed quantity (receipt / adjustment).
     *
     * @param  array{
     *   product_id:int,
     *   quantity:int,
     *   reason:string,
     *   reference:string,
     *   branch_id?:int,
     *   kind?:string,
     *   reason_code?:string,
     *   expires_on?:string|null,
     *   batch_number?:string|null
     * }  $data
     */
    public function adjustStock(User $actor, array $data): Product
    {
        $this->assertCompanyHandler($actor);
        abort_unless($actor->allows('inventory'), 403);
        $branch = $this->resolveWritableBranch($actor, isset($data['branch_id']) ? (int) $data['branch_id'] : null);

        return DB::transaction(function () use ($actor, $data, $branch): Product {
            if (DB::table('stock_movements')->where('reference', $data['reference'])->exists()) {
                throw ValidationException::withMessages(['reference' => __('portal.err_stock_ref_dup')]);
            }

            $product = Product::query()->whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            $stock = $this->ensureBranchStock($branch, $product);

            [$quantity, $kind, $reason] = $this->resolveMovementDirection($data);
            $next = (int) $stock->quantity + $quantity;
            if ($quantity === 0 || abs($quantity) > 100000 || $next < 0 || $next > 2147483647) {
                throw ValidationException::withMessages(['quantity' => __('portal.err_stock_nonnegative')]);
            }

            $previousCompany = (int) $product->stock;
            $stock->quantity = $next;
            $stock->save();

            $companyTotal = (int) BranchProductStock::query()->where('product_id', $product->id)->sum('quantity');
            $productUpdates = ['stock' => $companyTotal];
            if (
                $kind === self::KindProductIn
                && array_key_exists('expires_on', $data)
                && $data['expires_on']
                && Schema::hasColumn('products', 'expires_on')
            ) {
                $productUpdates['expires_on'] = $data['expires_on'];
            }
            $batchNumber = trim((string) ($data['batch_number'] ?? ''));
            if ($kind === self::KindProductIn && $batchNumber !== '') {
                $productUpdates['batch_number'] = $batchNumber;
            }
            $product->forceFill($productUpdates)->save();

            DB::table('stock_movements')->insert([
                'product_id' => $product->id,
                'branch_id' => $branch->id,
                'quantity' => $quantity,
                'reason' => $reason,
                'reference' => $data['reference'],
                'kind' => $kind,
                'actor_id' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'stock.adjusted', (string) $product->id, [
                'quantity' => $quantity,
                'kind' => $kind,
                'reason' => $reason,
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                ...($batchNumber !== '' && $kind === self::KindProductIn ? ['batch_number' => $batchNumber] : []),
            ]);
            $this->notifications->recordChange($product, $previousCompany, $previousCompany > 0 && ! $product->is_stock_paused);

            return $product->refresh();
        });
    }

    /**
     * @param  array{quantity:int,reason:string,kind?:string,reason_code?:string}  $data
     * @return array{0:int,1:string,2:string}
     */
    private function resolveMovementDirection(array $data): array
    {
        $kind = isset($data['kind']) ? trim((string) $data['kind']) : '';
        $absolute = abs((int) $data['quantity']);
        $reasonCode = isset($data['reason_code']) ? trim((string) $data['reason_code']) : '';
        $reason = trim((string) $data['reason']);

        if ($kind === self::KindProductIn) {
            $this->assertReasonCode($reasonCode !== '' ? $reasonCode : $reason, self::ProductInReasonCodes, self::KindProductIn);

            return [$absolute, self::KindProductIn, $this->composeReason($reasonCode, $reason)];
        }

        if ($kind === self::KindProductOut) {
            $this->assertReasonCode($reasonCode !== '' ? $reasonCode : $reason, self::ProductOutReasonCodes, self::KindProductOut);

            return [-$absolute, self::KindProductOut, $this->composeReason($reasonCode, $reason)];
        }

        $signed = (int) $data['quantity'];

        return [$signed, $signed > 0 ? 'receipt' : 'adjustment', $reason];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertReasonCode(string $candidate, array $allowed, string $kind): void
    {
        if ($candidate === '') {
            throw ValidationException::withMessages(['reason' => __('portal.err_stock_reason_required')]);
        }

        // Free-text reasons remain valid; structured codes must match the kind allow-list.
        if (! str_contains($candidate, ' ') && ! in_array($candidate, $allowed, true) && preg_match('/^[a-z][a-z0-9_]*$/', $candidate) === 1) {
            throw ValidationException::withMessages([
                'reason_code' => __('portal.err_stock_reason_code', ['kind' => $kind]),
            ]);
        }
    }

    private function composeReason(string $reasonCode, string $reason): string
    {
        if ($reasonCode !== '' && $reason !== '' && $reason !== $reasonCode) {
            return $reasonCode.': '.$reason;
        }

        return $reasonCode !== '' ? $reasonCode : $reason;
    }

    /**
     * Goods are sold and moved only by the company. A dealer or sub-dealer acting
     * as a partner can never send stock out of a branch, dispatch an order, or
     * move stock to another partner; only company staff (or a partner signed in
     * to a linked staff workspace, acting for the company) can.
     */
    public function assertCompanyHandler(User $actor): void
    {
        abort_if(
            in_array($actor->role, ['dealer', 'sub_dealer'], true) && ! $actor->usingStaffWorkspace(),
            403,
            __('portal.err_partner_cannot_sell'),
        );
    }

    public function resolveWritableBranch(User $actor, ?int $branchId = null): CompanyBranch
    {
        $this->assertCompanyHandler($actor);
        $id = $branchId ?: (int) $actor->active_branch_id;
        if ($id < 1) {
            $id = (int) CompanyBranch::query()->active()->orderBy('id')->value('id');
        }
        abort_if($id < 1, 422, __('portal.err_branch_required'));

        $branch = CompanyBranch::query()->whereKey($id)->firstOrFail();
        abort_unless($branch->is_active && $branch->is_stock_hub, 422, __('portal.err_branch_not_stock_hub'));

        if (! $actor->owner() && ! $this->canManageAllBranches($actor)) {
            $assigned = BranchStaffAssignment::query()
                ->where('branch_id', $branch->id)
                ->where('user_id', $actor->id)
                ->exists();
            abort_unless($assigned, 403);
        }

        return $branch;
    }

    public function canManageAllBranches(User $actor): bool
    {
        return $actor->owner() || $actor->allows('accounts') || $actor->allows('inventory');
    }

    /**
     * Who may look at every branch: everyone who may manage all branches, plus
     * finance staff (accountants), who read every branch's figures but get no
     * extra right to change stock at a branch they are not assigned to.
     */
    public function canViewAllBranches(User $actor): bool
    {
        return $this->canManageAllBranches($actor) || $actor->allows('finance');
    }

    /**
     * Whether a staff member may open this order. The owner and all-branch staff always may;
     * branch staff may open orders of the branches they work at. An order that has no branch
     * yet belongs to no other branch, so it stays open to them.
     */
    public function mayOpenOrder(User $actor, Order $order): bool
    {
        if ($this->canViewAllBranches($actor) || $order->fulfillment_branch_id === null) {
            return true;
        }

        return BranchStaffAssignment::query()
            ->where('branch_id', $order->fulfillment_branch_id)
            ->where('user_id', $actor->id)
            ->exists();
    }

    /**
     * The branches a staff page shows, following the top-bar switcher the same way
     * Branch performance does: the owner and staff who may view every branch see all
     * branches until they pick one (or when they pick "All branches"); a picked branch
     * filters the page; branch staff only ever see a branch they are assigned to.
     * A `branch_id` in the address that the actor may not see is refused with 403.
     *
     * @param  Collection<int, CompanyBranch>|null  $visible  Branches already loaded by {@see visibleBranches()}.
     */
    public function scopeFor(User $actor, Request $request, ?Collection $visible = null): BranchScope
    {
        $visible ??= $this->visibleBranches($actor);
        $requested = (int) $request->query('branch_id', '0');
        if ($requested > 0) {
            $branch = $visible->firstWhere('id', $requested);
            abort_unless($branch instanceof CompanyBranch, 403);

            return new BranchScope(false, [$branch->id], $branch);
        }

        if ($this->canViewAllBranches($actor)) {
            $switcherView = $request->hasSession() ? $request->session()->get(self::SessionView) : null;
            $noSwitcherChoiceYet = $switcherView === null && $actor->active_branch_id === null;
            if ($switcherView === 'all' || $noSwitcherChoiceYet || $request->boolean('all_branches')) {
                return new BranchScope(true, []);
            }
        }

        $branch = $this->preferredAmong($visible, $actor);

        return new BranchScope(false, $branch ? [$branch->id] : [], $branch);
    }

    public function preferredBranch(User $actor): ?CompanyBranch
    {
        return $this->preferredAmong($this->visibleBranches($actor), $actor);
    }

    /**
     * The branch picker in one read of the branch list: the branch the actor
     * works in and every branch they may switch to. Same values as calling
     * preferredBranch() and branchesFor(), without loading the branches twice.
     *
     * @return array{preferred: ?CompanyBranch, branches: list<array<string, mixed>>}
     */
    public function branchChoices(User $actor): array
    {
        $branches = $this->visibleBranches($actor);

        return [
            'preferred' => $this->preferredAmong($branches, $actor),
            'branches' => $branches->map(fn (CompanyBranch $branch) => $branch->summary())->all(),
        ];
    }

    /**
     * The branch shown as active in the top-bar switcher, picked from branches already loaded.
     *
     * @param  Collection<int, CompanyBranch>  $branches
     */
    public function preferredAmong(Collection $branches, User $actor): ?CompanyBranch
    {
        if ($actor->active_branch_id) {
            $saved = $branches->firstWhere('id', (int) $actor->active_branch_id);
            if ($saved instanceof CompanyBranch) {
                return $saved;
            }
        }

        return $branches->first(fn (CompanyBranch $branch): bool => (bool) $branch->is_head_office)
            ?? $branches->first(fn (CompanyBranch $branch): bool => str_starts_with(mb_strtolower($branch->name), 'head office'))
            ?? $branches->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function branchesFor(User $actor): array
    {
        return $this->visibleBranches($actor)
            ->map(fn (CompanyBranch $branch) => $branch->summary())
            ->all();
    }

    /**
     * Branches the actor may see: every branch for the owner and staff who may view
     * all branches ({@see canViewAllBranches()}), otherwise only the branches the
     * actor is assigned to.
     *
     * @return Collection<int, CompanyBranch>
     */
    public function visibleBranches(User $actor): Collection
    {
        $query = CompanyBranch::query()->orderBy('name');
        if (! $this->canViewAllBranches($actor)) {
            $ids = BranchStaffAssignment::query()->where('user_id', $actor->id)->pluck('branch_id');
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deliveryPoints(): array
    {
        return CompanyBranch::query()
            ->deliveryPoints()
            ->orderBy('division')
            ->orderBy('name')
            ->get()
            ->map(fn (CompanyBranch $branch) => $branch->summary())
            ->all();
    }

    public function syncProductRollup(int $productId): void
    {
        $total = (int) BranchProductStock::query()->where('product_id', $productId)->sum('quantity');
        Product::query()->whereKey($productId)->update(['stock' => $total]);
    }

    /**
     * Ensure a branch stock row exists. Legacy products that only have company-wide
     * `products.stock` are attributed once to the first hub that touches them.
     */
    public function ensureBranchStock(CompanyBranch $branch, Product $product): BranchProductStock
    {
        $stock = BranchProductStock::query()->firstOrCreate(
            ['branch_id' => $branch->id, 'product_id' => $product->id],
            ['quantity' => 0, 'low_stock_at' => (int) $product->low_stock_at],
        );

        if ($stock->wasRecentlyCreated) {
            $other = (int) BranchProductStock::query()
                ->where('product_id', $product->id)
                ->where('id', '!=', $stock->id)
                ->sum('quantity');
            if ($other === 0 && (int) $product->stock > 0) {
                $stock->forceFill(['quantity' => (int) $product->stock])->save();
            }
        }

        return BranchProductStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
    }
}
