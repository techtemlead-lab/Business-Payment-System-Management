<?php

namespace App\Http\Controllers;

use App\Domain\Clinic\PrescriberVerification;
use App\Domain\Clinic\PrescriptionAccess;
use App\Models\PrescriberRegistration;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The per-account prescribing switch: for partners on the accounts page, for office accounts
 * on the employees page. Owners and staff with the "prescriber_verify" permission turn it on
 * for partners after checking the registration offline; only owners do it for office accounts.
 */
class PrescriberVerificationController extends Controller
{
    /** Office accounts listed at once; a search by name reaches the rest. */
    private const OfficeAccountLimit = 50;

    public function __construct(private PrescriberVerification $verification) {}

    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless(PrescriptionAccess::canVerify($request->user()), 403);
        abort_unless(PrescriptionAccess::isPartner($user) || PrescriptionAccess::isOfficeAccount($user), 404);
        abort_if(PrescriptionAccess::isOfficeAccount($user) && ! PrescriptionAccess::canManageCompanyStock($request->user()), 403, __('prescription.err_company_stock_owner_only'));

        return response()->json([
            'can_edit' => true,
            'types' => PrescriberRegistration::Types,
            ...$this->verification->status($user),
        ]);
    }

    /** Office accounts (MD, Chairman, staff) for the company-stock switch; those switched on come first. */
    public function officeAccounts(Request $request): JsonResponse
    {
        abort_unless(PrescriptionAccess::canManageCompanyStock($request->user()), 403, __('prescription.err_company_stock_owner_only'));
        $data = $request->validate(['q' => 'nullable|string|max:80']);
        $term = trim((string) ($data['q'] ?? ''));
        $accounts = User::query()
            ->staffAccounts()
            ->where('role', '!=', 'super_admin')
            ->when($term !== '', fn ($query) => $query->where('name', 'ilike', '%'.$term.'%'))
            ->orderByDesc('can_prescribe')
            ->orderBy('name')
            ->limit(self::OfficeAccountLimit)
            ->get(['id', 'name', 'role', 'status', 'can_prescribe'])
            ->map(fn (User $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'role' => $account->role,
                'status' => $account->status,
                'enabled' => (bool) $account->can_prescribe,
            ]);

        return response()->json(['data' => $accounts]);
    }

    public function enable(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'registration_type' => ['required', Rule::in(PrescriberRegistration::Types)],
            'registration_number' => 'required|string|min:2|max:60',
            'note' => 'nullable|string|max:1000',
        ]);
        $this->verification->enable($request->user(), $user, $data);

        return back()->with('success', __('prescription.flash_enabled', ['name' => $user->name]));
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $this->verification->disable($request->user(), $user, $data['reason'] ?? null);

        return back()->with('success', __('prescription.flash_disabled', ['name' => $user->name]));
    }
}
