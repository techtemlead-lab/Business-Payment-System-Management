<?php

namespace App\Domain\Inventory;

use App\Models\BranchProductStock;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BranchOperations
{
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

            $payload = [
                'code' => $code,
                'name' => trim($data['name']),
                'division' => $data['division'] ?? null,
                'district' => $data['district'] ?? null,
                'upazila' => $data['upazila'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'place_code_id' => $data['place_code_id'] ?? null,
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

    public function selectActiveBranch(User $actor, CompanyBranch $branch): User
    {
        abort_unless($actor->staff() || $actor->owner(), 403);
        abort_unless($branch->is_active, 422, __('portal.err_branch_inactive'));

        if (! $actor->owner() && ! $actor->allows('inventory') && ! $actor->allows('accounts')) {
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
     * @param  array{product_id:int,quantity:int,reason:string,reference:string,branch_id?:int}  $data
     */
    public function adjustStock(User $actor, array $data): Product
    {
        abort_unless($actor->allows('inventory'), 403);
        $branch = $this->resolveWritableBranch($actor, isset($data['branch_id']) ? (int) $data['branch_id'] : null);

        return DB::transaction(function () use ($actor, $data, $branch): Product {
            if (DB::table('stock_movements')->where('reference', $data['reference'])->exists()) {
                throw ValidationException::withMessages(['reference' => __('portal.err_stock_ref_dup')]);
            }

            $product = Product::query()->whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            $stock = $this->ensureBranchStock($branch, $product);

            $quantity = (int) $data['quantity'];
            $next = (int) $stock->quantity + $quantity;
            if ($quantity === 0 || abs($quantity) > 100000 || $next < 0 || $next > 2147483647) {
                throw ValidationException::withMessages(['quantity' => __('portal.err_stock_nonnegative')]);
            }

            $previousCompany = (int) $product->stock;
            $stock->quantity = $next;
            $stock->save();

            $companyTotal = (int) BranchProductStock::query()->where('product_id', $product->id)->sum('quantity');
            $product->forceFill(['stock' => $companyTotal])->save();

            DB::table('stock_movements')->insert([
                'product_id' => $product->id,
                'branch_id' => $branch->id,
                'quantity' => $quantity,
                'reason' => $data['reason'],
                'reference' => $data['reference'],
                'kind' => $quantity > 0 ? 'receipt' : 'adjustment',
                'actor_id' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'stock.adjusted', (string) $product->id, [
                'quantity' => $quantity,
                'reason' => $data['reason'],
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
            ]);
            $this->notifications->recordChange($product, $previousCompany, $previousCompany > 0 && ! $product->is_stock_paused);

            return $product->refresh();
        });
    }

    public function resolveWritableBranch(User $actor, ?int $branchId = null): CompanyBranch
    {
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
     * @return list<array<string, mixed>>
     */
    public function branchesFor(User $actor): array
    {
        $query = CompanyBranch::query()->orderBy('name');
        if (! $this->canManageAllBranches($actor) && ! $actor->owner()) {
            $ids = BranchStaffAssignment::query()->where('user_id', $actor->id)->pluck('branch_id');
            $query->whereIn('id', $ids);
        }

        return $query->get()->map(fn (CompanyBranch $branch) => $branch->summary())->all();
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
