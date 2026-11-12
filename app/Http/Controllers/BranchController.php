<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\BranchOperations;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Support\ReasonFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request, BranchOperations $operations): JsonResponse
    {
        abort_unless($request->user()?->staff() || $request->user()?->owner(), 403);

        return response()->json([
            'data' => [
                'branches' => $operations->branchesFor($request->user()),
                'active_branch_id' => $request->user()->active_branch_id,
                'delivery_points' => $operations->deliveryPoints(),
            ],
        ]);
    }

    public function store(Request $request, BranchOperations $operations): RedirectResponse|JsonResponse
    {
        $data = $this->validatedBranch($request);
        $branch = $operations->saveBranch($request->user(), $data);
        if ($request->expectsJson()) {
            return response()->json(['data' => $branch->summary()], 201);
        }

        return back()->with('success', __('portal.flash_branch_saved', ['code' => $branch->code]));
    }

    public function update(Request $request, CompanyBranch $branch, BranchOperations $operations): RedirectResponse|JsonResponse
    {
        $data = $this->validatedBranch($request, $branch);
        $branch = $operations->saveBranch($request->user(), $data, $branch);
        if ($request->expectsJson()) {
            return response()->json(['data' => $branch->summary()]);
        }

        return back()->with('success', __('portal.flash_branch_updated', ['code' => $branch->code]));
    }

    public function assignStaff(Request $request, CompanyBranch $branch, BranchOperations $operations): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'duty' => 'nullable|in:'.implode(',', BranchStaffAssignment::Duties),
            'is_primary' => 'nullable|boolean',
        ]);
        $assignment = $operations->assignStaff($request->user(), $branch, $data);
        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'branch_id' => $assignment->branch_id,
                'user_id' => $assignment->user_id,
                'duty' => $assignment->duty,
                'is_primary' => $assignment->is_primary,
            ]]);
        }

        return back()->with('success', __('portal.flash_branch_staff_assigned'));
    }

    public function removeStaff(Request $request, CompanyBranch $branch, User $user, BranchOperations $operations): RedirectResponse|JsonResponse
    {
        $operations->removeStaff($request->user(), $branch, $user);
        if ($request->expectsJson()) {
            return response()->json(['data' => ['removed' => true]]);
        }

        return back()->with('success', __('portal.flash_branch_staff_removed'));
    }

    public function select(Request $request, CompanyBranch $branch, BranchOperations $operations): RedirectResponse|JsonResponse
    {
        $actor = $operations->selectActiveBranch($request->user(), $branch);
        $request->session()->forget(BranchOperations::SessionView);
        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'active_branch_id' => $actor->active_branch_id,
                'branch' => $branch->summary(),
            ]]);
        }

        return back()->with('success', __('portal.flash_branch_selected', ['name' => $branch->name]));
    }

    /**
     * Switch the top bar to "All branches". Only the owner and staff who may view
     * every branch can; branch staff stay on the branches they are assigned to.
     */
    public function viewAll(Request $request, BranchOperations $operations): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null && ($actor->staff() || $actor->owner()) && $operations->canViewAllBranches($actor), 403);
        $request->session()->put(BranchOperations::SessionView, 'all');

        return back()->with('success', __('portal.flash_branch_view_all'));
    }

    public function destroy(Request $request, CompanyBranch $branch, BranchOperations $operations): RedirectResponse
    {
        $operations->deleteBranch($request->user(), $branch);

        return back()->with('success', __('portal.flash_branch_deleted', ['name' => $branch->name]));
    }

    public function deliveryPoints(BranchOperations $operations): JsonResponse
    {
        return response()->json(['data' => $operations->deliveryPoints()]);
    }

    /**
     * A branch is placed by its place code (typed as MNHC-…, or a place code id from the API);
     * its division/district/upazila names are filled in from that code, never typed.
     * The note follows the owner's `stock.branch_note` choice when a branch is added; an edit
     * that sends no note keeps the one on file, as before.
     *
     * @return array{
     *   code:string,name:string,place_code?:?string,
     *   address?:?string,phone?:?string,place_code_id?:?int,latitude?:?float,longitude?:?float,
     *   is_active?:bool,is_delivery_point?:bool,is_stock_hub?:bool,notes?:?string
     * }
     */
    private function validatedBranch(Request $request, ?CompanyBranch $branch = null): array
    {
        $required = $branch ? 'sometimes|required' : 'required';

        return $request->validate([
            'code' => $required.'|string|max:32',
            'name' => $required.'|string|max:150',
            'place_code' => 'nullable|string|max:32',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'place_code_id' => 'nullable|integer',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_active' => 'nullable|boolean',
            'is_delivery_point' => 'nullable|boolean',
            'is_stock_hub' => 'nullable|boolean',
            'notes' => [...($branch ? ['sometimes'] : []), ...ReasonFields::rule('stock.branch_note', 2000)],
        ]);
    }
}
