<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\PolicyDraftService;
use App\Domain\Inventory\StockService;
use App\Domain\Orders\OrderService;
use App\Domain\Staff\StaffPermissions;
use App\Http\Requests\Backend\AdjustStockRequest;
use App\Http\Requests\Backend\ClaimPaymentRequest;
use App\Http\Requests\Backend\ConfirmDeliveryRequest;
use App\Http\Requests\Backend\DecideOrderRequest;
use App\Http\Requests\Backend\DispatchOrderRequest;
use App\Http\Requests\Backend\StoreOrderRequest;
use App\Http\Requests\Backend\VerifyPaymentRequest;
use App\Models\AreaDealerAssignment;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Audit;
use App\Support\DashboardManagement;
use App\Support\Money;
use App\Support\SiteCustomization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommandController extends Controller
{
    private function permit(Request $r, string $cap): void
    {
        abort_unless($r->user()->allows($cap), 403);
    }

    public function order(StoreOrderRequest $r, OrderService $service): RedirectResponse
    {
        $o = $service->create($r->user(), $r->validated());

        return redirect('/orders/'.$o->id)->with('success', 'Order submitted for review.');
    }

    public function decision(DecideOrderRequest $r, Order $order, OrderService $s): RedirectResponse
    {
        $d = $r->validated();
        $s->decision($r->user(), $order, $d['status'], $d['reason'], $r->boolean('allow_credit'), $r->replacements());

        return back()->with('success', 'Order decision recorded.');
    }

    public function verify(VerifyPaymentRequest $r, int $claim, OrderService $s): RedirectResponse
    {
        $d = $r->validated();
        $s->verify($r->user(), $claim, $d['approve'], $d['reason']);

        return back()->with('success', 'Payment review recorded.');
    }

    public function dispatch(DispatchOrderRequest $r, Order $order, OrderService $s): RedirectResponse
    {
        $d = $r->validated();
        $s->dispatch($r->user(), $order, $d);

        return back()->with('success', 'Shipment recorded and stock updated.');
    }

    public function account(Request $r, PartnerAccountProvisioning $partners): RedirectResponse
    {
        $this->permit($r, 'accounts');
        $roles = ['dealer', 'sub_dealer'];
        if ($r->user()->role === 'super_admin') {
            $roles = [...$roles, 'employee', ...array_keys(config('madina.roles')), ...app(StaffPermissions::class)->roleKeys()];
        }
        $isPartner = in_array($r->input('role'), ['dealer', 'sub_dealer'], true);
        $usesPlaceOnboarding = $isPartner && ($r->filled('place_code') || $r->filled('identity_type') || $r->filled('refer_code'));

        if ($usesPlaceOnboarding) {
            if ($r->filled('nid')) {
                $this->permit($r, 'identity_write');
            }
            $payload = $r->validate([
                'role' => ['required', Rule::in(['dealer', 'sub_dealer'])],
                'identity_type' => 'required|in:birth_certificate,nid',
                'birth_certificate_number' => 'nullable|string|max:40',
                'nid' => 'nullable|string|max:30',
                'date_of_birth' => 'required|date',
                'name' => 'required|string|max:150',
                'name_bn' => 'required|string|max:150',
                'father_name' => 'required|string|max:150',
                'mother_name' => 'required|string|max:150',
                'occupation' => 'nullable|string|max:150',
                'division' => 'nullable|string|max:80',
                'district' => 'nullable|string|max:80',
                'upazila' => 'nullable|string|max:150',
                'union' => 'nullable|string|max:150',
                'village' => 'nullable|string|max:150',
                'post_office' => 'nullable|string|max:150',
                'place_code' => 'required|string|max:32',
                'dealer_code' => 'nullable|string|max:40',
                'reference_code' => 'nullable|string|max:40',
                'refer_code' => 'required|string|max:40',
                'phone' => 'required|string|max:30',
                'email' => 'required|email|max:254',
                'extra_contact' => 'nullable|string|max:80',
                'pharmacy' => 'nullable|string|max:150',
                'directory_visible' => 'sometimes|boolean',
                'status' => 'sometimes|in:active,suspended',
                'reason' => 'nullable|string|max:1000',
            ]);
            $created = $partners->create($r->user(), $payload);

            return redirect('/accounts')->with(
                'success',
                ucfirst(str_replace('_', '-', $created['user']->role)).' account created. Code: '.$created['user']->dealer_code.
                '. Login SMS sent to '.$created['user']->phone.' (username = mobile number).'
            );
        }

        $d = $r->validate([
            'role' => ['required', Rule::in($roles)],
            'dealer_code' => $isPartner ? ['required', 'string', 'max:40', 'unique:users,dealer_code'] : ['nullable', 'string', 'max:40', 'unique:users,dealer_code'],
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:254|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'division' => 'nullable|string|max:80',
            'district' => 'nullable|string|max:80',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'directory_visible' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,suspended',
            'sponsor_id' => 'nullable|integer|exists:users,id',
            'nid' => 'nullable|string|max:30',
            'reason' => 'nullable|string|max:1000',
            'permissions' => [Rule::prohibitedIf($r->user()->role !== 'super_admin' || ! in_array($r->input('role'), ['employee', 'sr'], true)), 'nullable', 'array'],
            'permissions.*' => ['string', Rule::in(config($r->input('role') === 'sr' ? 'madina.roles.sr' : 'madina.roles.super_admin'))],
            'profile' => 'sometimes|array:name_bn,father_name,mother_name,village,post_office,upazila,union,pharmacy,area_code',
            'profile.name_bn' => 'nullable|string|max:150',
            'profile.father_name' => 'nullable|string|max:150',
            'profile.mother_name' => 'nullable|string|max:150',
            'profile.village' => 'nullable|string|max:150',
            'profile.post_office' => 'nullable|string|max:150',
            'profile.upazila' => 'nullable|string|max:150',
            'profile.union' => 'nullable|string|max:150',
            'profile.pharmacy' => 'nullable|string|max:150',
            'profile.area_code' => 'nullable|string|max:80',
        ]);
        $profileKeys = ['name_bn', 'father_name', 'mother_name', 'village', 'post_office', 'upazila', 'union', 'pharmacy', 'area_code'];
        $rootProfile = $r->validate([...array_fill_keys($profileKeys, 'nullable|string|max:150'), 'area_code' => 'nullable|string|max:80']);
        $profileData = $d['profile'] ?? [];
        foreach ($profileKeys as $key) {
            if ($r->filled($key)) {
                $profileData[$key] = trim((string) $rootProfile[$key]);
            }
        }
        if ($r->has('permissions')) {
            $profileData['permissions'] = array_values(array_filter((array) $r->input('permissions')));
        }
        $d['profile'] = $profileData;
        unset($d['permissions']);
        if (! empty($d['nid'])) {
            $this->permit($r, 'identity_write');
        }
        if (! empty($d['sponsor_id'])) {
            abort_unless(User::whereKey($d['sponsor_id'])->where('role', 'dealer')->where('status', 'active')->exists(), 422, 'Sponsor must be an active dealer.');
        }
        if (($d['directory_visible'] ?? false) && $d['role'] !== 'dealer') {
            throw ValidationException::withMessages(['directory_visible' => 'Only approved dealer business contacts may be published. Sub-dealer publication is not approved.']);
        }
        $u = DB::transaction(function () use ($r, $d, $isPartner) {
            CommissionWallet::lock();
            $role = $d['role'];
            $dealerCode = $isPartner ? trim((string) $d['dealer_code']) : ($d['dealer_code'] ?? null);
            $reason = $d['reason'] ?? 'Account created by company staff';
            unset($d['reason']);
            $u = User::create([
                ...$d,
                'dealer_code' => $dealerCode,
                'password' => Str::random(48),
            ]);
            DB::table('wallets')->insert(['user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            ReferralTreePath::attachUser($u->id, $u->sponsor_id);
            DealerRank::firstOrCreate(['user_id' => $u->id], ['current_rank' => 'dealer']);
            Audit::record($r->user()->id, 'account.created', (string) $u->id, [
                'role' => $role,
                'dealer_code' => $dealerCode,
                'reason' => $reason,
            ]);

            return $u;
        });
        Password::sendResetLink(['email' => $u->email]);
        $redirectUrl = ($d['role'] ?? '') === 'employee' ? '/employees' : '/accounts';
        $accountTypeLabel = ($d['role'] ?? '') === 'employee' ? 'Employee' : 'Partner';

        return redirect($redirectUrl)->with('success', $accountTypeLabel.' account created. Unique code: '.($u->dealer_code ?? $u->name).'. A private password-setting invitation has been emailed.');
    }

    public function accountUpdate(Request $r, User $user): RedirectResponse
    {
        $this->permit($r, 'accounts');
        abort_if($user->staff() && $r->user()->role !== 'super_admin', 403);
        abort_if($r->user()->id === $user->id, 422, 'Use your security page; self-suspension is not allowed.');
        if ($r->exists('nid')) {
            $this->permit($r, 'identity_write');
        }
        $d = $r->validate(['status' => 'sometimes|in:active,suspended', 'name' => 'sometimes|required|string|max:150', 'dealer_code' => 'sometimes|nullable|string|max:40|unique:users,dealer_code,'.$user->id, 'phone' => 'nullable|string|max:30', 'division' => 'nullable|string|max:80', 'district' => 'nullable|string|max:80', 'latitude' => 'sometimes|nullable|numeric|between:-90,90', 'longitude' => 'sometimes|nullable|numeric|between:-180,180', 'sponsor_id' => 'sometimes|nullable|integer|exists:users,id', 'directory_visible' => 'sometimes|boolean', 'reason' => 'required|string|max:1000',
            'permissions' => [Rule::prohibitedIf($r->user()->role !== 'super_admin' || ! in_array($user->role, ['employee', 'sr'], true)), 'sometimes', 'nullable', 'array'],
            'permissions.*' => ['string', Rule::in(config($user->role === 'sr' ? 'madina.roles.sr' : 'madina.roles.super_admin'))],
            'profile' => 'sometimes|array:name_bn,father_name,mother_name,village,post_office,upazila,union,pharmacy,area_code', 'profile.name_bn' => 'nullable|string|max:150', 'profile.father_name' => 'nullable|string|max:150', 'profile.mother_name' => 'nullable|string|max:150', 'profile.village' => 'nullable|string|max:150', 'profile.post_office' => 'nullable|string|max:150', 'profile.upazila' => 'nullable|string|max:150', 'profile.union' => 'nullable|string|max:150', 'profile.pharmacy' => 'nullable|string|max:150', 'profile.area_code' => 'nullable|string|max:80', 'nid' => 'sometimes|nullable|string|max:30']);
        if ($r->has('sponsor_id')) {
            $newSponsorId = ! empty($d['sponsor_id']) ? (int) $d['sponsor_id'] : null;
            if ($newSponsorId) {
                if ($newSponsorId === $user->id) {
                    throw ValidationException::withMessages(['sponsor_id' => 'Self-sponsorship is disallowed. A partner cannot be their own sponsor.']);
                }
                abort_unless(User::whereKey($newSponsorId)->where('role', 'dealer')->where('status', 'active')->exists(), 422, 'Sponsor must be an active dealer.');
                if (ReferralTreePath::isDescendant($user->id, $newSponsorId)) {
                    throw ValidationException::withMessages(['sponsor_id' => 'Circular referral cycle detected: The selected sponsor is already a downline descendant of this user.']);
                }
            }
        }
        $profileKeys = ['name_bn', 'father_name', 'mother_name', 'village', 'post_office', 'upazila', 'union', 'pharmacy', 'area_code'];
        $rootProfile = $r->validate([...array_fill_keys($profileKeys, 'nullable|string|max:150'), 'area_code' => 'nullable|string|max:80']);
        if ($r->has('profile') || $r->hasAny($profileKeys) || $r->has('permissions')) {
            $existing = $user->profile ?? [];
            $incoming = $d['profile'] ?? [];
            foreach ($profileKeys as $key) {
                if ($r->filled($key)) {
                    $incoming[$key] = trim((string) $rootProfile[$key]);
                }
            }
            if ($r->has('permissions')) {
                if (isset($existing['action_permissions'])) {
                    throw ValidationException::withMessages(['permissions' => 'Manage this account in Roles & permissions to retain its detailed action controls.']);
                }
                $incoming['permissions'] = array_values(array_filter((array) $r->input('permissions')));
            }
            $d['profile'] = array_merge($existing, $incoming);
        }
        unset($d['permissions']);
        DB::transaction(function () use ($r, $user, $d) {
            CommissionWallet::lock();
            $reason = $d['reason'];
            unset($d['reason']);
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('sponsor_id', $d) && (int) $locked->sponsor_id !== (int) ($d['sponsor_id'] ?? null)) {
                ReferralTreePath::reparentUser($locked->id, ! empty($d['sponsor_id']) ? (int) $d['sponsor_id'] : null);
            }

            $locked->update($d);
            if (($d['status'] ?? '') === 'suspended') {
                $locked->update(['remember_token' => Str::random(60)]);
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            Audit::record($r->user()->id, 'account.updated', (string) $user->id, ['fields' => array_keys($d), 'reason' => $reason]);
        });

        return back()->with('success', 'Account updated.');
    }

    public function product(Request $r): RedirectResponse
    {
        $this->permit($r, 'catalog');
        abort_unless($r->user()->role === 'super_admin', 403);
        $d = $r->validate(['name' => 'required|string|max:150', 'sku' => 'required|string|max:60|unique:products', 'category' => 'required|string|max:100', 'description' => 'nullable|string|max:3000',
            'mrp' => 'required|regex:/^\d{1,8}(\.\d{1,2})?$/', 'dealer_price' => 'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/', 'unit' => 'required|string|max:30']);
        if (Money::cents($d['mrp']) <= 0 || (isset($d['dealer_price']) && (Money::cents($d['dealer_price']) <= 0 || Money::cents($d['dealer_price']) > Money::cents($d['mrp'])))) {
            throw ValidationException::withMessages(['mrp' => 'Enter positive prices; dealer price cannot exceed MRP.']);
        }
        DB::transaction(function () use ($r, $d) {
            $p = Product::create($d);
            Audit::record($r->user()->id, 'product.created', (string) $p->id);
        });

        return back()->with('success', 'Product created. Receive stock before dispatch.');
    }

    public function stock(AdjustStockRequest $r, StockService $service): RedirectResponse
    {
        $service->adjust($r->user(), $r->validated());

        return back()->with('success', 'Stock movement recorded.');
    }

    public function policy(Request $r, PolicyDraftService $service): RedirectResponse
    {
        $this->permit($r, 'finance');
        $d = $r->validate(['name' => 'required|string|max:150', 'account_type' => 'required|in:dealer,sub_dealer', 'components' => 'required|array|min:1|max:20', 'components.*.name' => 'required|string|max:80', 'components.*.rate' => 'required|numeric|min:0|max:100', 'notes' => 'required|string|max:4000']);
        $service->create($r->user(), $d);

        return back()->with('success', 'Draft saved. No earnings will be posted.');
    }

    public function content(Request $r): RedirectResponse
    {
        $this->permit($r, 'content');
        $d = $r->validate([
            'slug' => 'required|in:home,about,contact,notice,partnership,services,quality,faq,privacy,terms,products,dealers',
            'title' => 'required|string|max:150',
            'body' => 'required|string|max:10000',
            'published' => 'required|boolean',
        ]);
        DB::transaction(function () use ($r, $d) {
            $existing = DB::table('content_pages')->where('slug', $d['slug'])->exists();
            DB::table('content_pages')->updateOrInsert(
                ['slug' => $d['slug']],
                [...$d, 'updated_at' => now(), ...($existing ? [] : ['created_at' => now()])]
            );
            Audit::record($r->user()->id, 'content.updated', $d['slug']);
        });

        return back()->with('success', 'Content saved.');
    }

    public function publishPolicy(Request $r, int $id, PolicyDraftService $service): RedirectResponse
    {
        $this->permit($r, 'finance');
        $service->publish($r->user(), $id);

        return back()->with('success', 'Policy draft marked published for review. Live commission rates still use Commission Studio.');
    }

    public function customization(Request $r): RedirectResponse
    {
        abort_unless($r->user()->allows('content') || $r->user()->allows('catalog') || $r->user()->role === 'super_admin', 403);
        $payload = $r->validate(['customization' => 'required|array'])['customization'];
        SiteCustomization::save($payload);
        Audit::record($r->user()->id, 'customization.updated', 'frontend');

        return back()->with('success', 'Site customization saved successfully.');
    }

    public function resetCustomization(Request $r): RedirectResponse
    {
        $this->permit($r, 'content');
        SiteCustomization::reset();
        Audit::record($r->user()->id, 'customization.reset', 'frontend');

        return back()->with('success', 'Customization reset to company defaults.');
    }

    public function saveDashboardSettings(Request $r): RedirectResponse
    {
        abort_unless($r->user()->role === 'super_admin' || $r->user()->allows('accounts'), 403);
        $payload = $r->input('settings', []);
        if (! is_array($payload)) {
            $payload = [];
        }
        DashboardManagement::save($payload);
        Audit::record($r->user()->id, 'dashboard.updated', 'control_center');

        return back()->with('success', 'Dashboard management configuration saved successfully.');
    }

    public function resetDashboardSettings(Request $r): RedirectResponse
    {
        abort_unless($r->user()->role === 'super_admin' || $r->user()->allows('accounts'), 403);
        DashboardManagement::reset();
        Audit::record($r->user()->id, 'dashboard.reset', 'control_center');

        return back()->with('success', 'Dashboard settings reset to system defaults.');
    }

    public function enquiry(Request $r, int $id): RedirectResponse
    {
        $this->permit($r, 'content');
        $d = $r->validate(['status' => 'required|in:new,contacted,closed']);
        abort_unless(DB::table('enquiries')->where('id', $id)->exists(), 404);
        DB::table('enquiries')->where('id', $id)->update([...$d, 'updated_at' => now()]);
        Audit::record($r->user()->id, 'enquiry.updated', (string) $id);

        return back()->with('success', 'Enquiry updated.');
    }

    public function readNotice(Request $r, int $id): RedirectResponse
    {
        abort_unless(DB::table('account_notices')->where('id', $id)->where('user_id', $r->user()->id)->update(['read_at' => now()]), 404);

        return back();
    }

    public function financialGate(): never
    {
        throw ValidationException::withMessages(['policy' => 'This action is unavailable until the financial policy is approved and verified.']);
    }

    public function claim(ClaimPaymentRequest $r, Order $order, OrderService $service): RedirectResponse
    {
        $data = $r->validated();
        $service->claim($r->user(), $order, $data);

        return back()->with('success', 'Payment reference submitted for verification.');
    }

    public function delivered(ConfirmDeliveryRequest $r, Order $order, int $shipment, OrderService $service): RedirectResponse
    {
        $data = $r->validated();
        $service->deliver($r->user(), $order, $shipment, $data['reason']);

        return back()->with('success', 'Delivery confirmation recorded.');
    }

    public function invite(Request $r, User $user): RedirectResponse
    {
        $this->permit($r, 'accounts');
        abort_if($user->staff() && $r->user()->role !== 'super_admin', 403);
        abort_unless($user->status === 'active', 422);
        Password::sendResetLink(['email' => $user->email]);
        Audit::record($r->user()->id, 'account.invitation_sent', (string) $user->id);

        return back()->with('success', 'A private password-setting link has been emailed if the account is eligible.');
    }

    public function updateCommissionRules(Request $r, CommissionPolicy $policy): RedirectResponse
    {
        $this->permit($r, 'finance');
        $data = $r->validate(['rules' => 'required|array']);
        $policy->update($r->user(), $data['rules']);

        return back()->with('success', 'Commission policy updated. Existing orders retain their captured policy.');
    }

    public function settleSalaryPool(Request $r, CommissionService $commissionService): RedirectResponse
    {
        $this->permit($r, 'finance');
        $d = $r->validate(['period_month' => 'required|date_format:Y-m']);
        $result = $commissionService->settleMonthlySalaryPool($d['period_month'], $r->user()->id);

        if ($result['status'] === 'empty_pool') {
            return back()->with('success', "Salary pool for {$d['period_month']} is empty.");
        }
        if ($result['status'] === 'rolled_over') {
            return back()->with('success', "No qualified dealers met minimum volume for {$d['period_month']}. ৳{$result['total_rolled_to_reserve']} moved to the salary settlement reserve.");
        }

        $rolled = $result['total_rolled_to_reserve'] ?? '0.00';
        $extra = Money::cents((string) $rolled) > 0 ? " Undistributed ৳{$rolled} moved to the salary settlement reserve." : '';

        return back()->with('success', "Salary pool for {$d['period_month']} distributed ৳{$result['total_distributed']}. Qualifying partners: {$result['qualifiers_count']}.{$extra}");
    }

    public function settleRankRoyaltyPool(Request $r, CommissionService $commissionService): RedirectResponse
    {
        $this->permit($r, 'finance');
        $d = $r->validate(['period_month' => 'required|date_format:Y-m']);
        $result = $commissionService->settleRankRoyaltyPool($d['period_month'], $r->user()->id);

        if ($result['status'] === 'empty_pool') {
            return back()->with('success', "Rank royalty pool for {$d['period_month']} is empty.");
        }

        $rolled = $result['total_rolled_to_reserve'] ?? '0.00';
        $extra = Money::cents((string) $rolled) > 0 ? " Unfilled tiers ৳{$rolled} moved to the royalty settlement reserve." : '';

        return back()->with('success', "Rank royalty pool for {$d['period_month']} distributed ৳{$result['total_distributed']} across leadership tiers.{$extra}");
    }

    public function claimRankIncentive(Request $r, User $user, CommissionService $commissionService): RedirectResponse
    {
        $this->permit($r, 'finance');
        $result = $commissionService->claimOneTimeRankIncentive($user->id, actorId: $r->user()->id);

        if (! $result['success']) {
            throw ValidationException::withMessages(['incentive' => $result['message']]);
        }

        return back()->with('success', "Paid one-time incentive of ৳{$result['amount']} to {$user->name} for rank ".strtoupper($result['rank']).'.');
    }

    public function releaseMaturedCommissions(Request $r, CommissionService $commissionService): RedirectResponse
    {
        $this->permit($r, 'finance');
        $result = $commissionService->releaseMaturedCommissions($r->user()->id);

        if ($result['status'] === 'no_matured_commissions') {
            return back()->with('success', 'No matured commissions pending release.');
        }

        return back()->with('success', "Released {$result['released_count']} matured allocations (৳{$result['total_amount']}) to dealer available balances.");
    }

    public function simulateCommissions(Request $r, CommissionService $commissionService): JsonResponse
    {
        $this->permit($r, 'finance');
        $overrides = $r->input('overrides', []);
        if (! is_array($overrides)) {
            $overrides = [];
        }
        $result = $commissionService->simulatePlan($overrides);

        return response()->json($result);
    }

    public function assignAreaDealer(Request $r): RedirectResponse
    {
        $this->permit($r, 'accounts');
        $d = $r->validate([
            'user_id' => 'required|exists:users,id',
            'level' => 'required|in:division,district,upazila,union',
            'place_code_id' => 'nullable|integer|exists:place_codes,id',
            'zone_name' => 'nullable|string|max:128',
            'division' => 'nullable|string|max:64',
            'district' => 'nullable|string|max:64',
            'upazila' => 'nullable|string|max:64',
            'union' => 'nullable|string|max:64',
            'monthly_quota' => 'required|numeric|min:0|max:99999999999|decimal:0,2',
        ]);

        $place = null;
        if (! empty($d['place_code_id'])) {
            $place = PlaceCode::query()
                ->whereKey($d['place_code_id'])
                ->where('level', $d['level'])
                ->where('is_active', true)
                ->first();
            if (! $place) {
                throw ValidationException::withMessages(['place_code_id' => 'Choose an active place code that matches the assignment level.']);
            }
            $path = $place->loadMissing('parent.parent.parent')->pathNames();
            foreach (['division', 'district', 'upazila', 'union'] as $field) {
                if ($field === $d['level']) {
                    $trimmed = [];
                    foreach (['division', 'district', 'upazila', 'union'] as $step) {
                        $trimmed[$step] = $path[$step];
                        if ($step === $field) {
                            break;
                        }
                    }
                    $path = $trimmed;
                    break;
                }
            }
            foreach ($path as $field => $value) {
                if (trim((string) $value) === '') {
                    throw ValidationException::withMessages(['place_code_id' => 'The selected place code is missing its parent hierarchy.']);
                }
                $d[$field] = $value;
            }
            $d['zone_name'] = trim((string) ($d['zone_name'] ?? '')) !== ''
                ? trim((string) $d['zone_name'])
                : $place->name.' ('.$place->code.')';
        } else {
            $path = [];
            foreach (['division', 'district', 'upazila', 'union'] as $field) {
                if (empty($d[$field])) {
                    throw ValidationException::withMessages([$field => 'Specify a place code or the full territorial hierarchy for this assignment.']);
                }
                $d[$field] = trim($d[$field]);
                $path[$field] = $d[$field];
                if ($field === $d['level']) {
                    break;
                }
            }
            if (trim((string) ($d['zone_name'] ?? '')) === '') {
                throw ValidationException::withMessages(['zone_name' => 'Enter a zone name for legacy name-based assignments.']);
            }
        }

        DB::transaction(function () use ($d, $path, $place): void {
            CommissionWallet::lock();
            $user = User::whereKey($d['user_id'])->lockForUpdate()->firstOrFail();
            if ($user->role !== 'dealer' || $user->status !== 'active') {
                throw ValidationException::withMessages(['user_id' => 'Choose an active dealer.']);
            }
            $query = AreaDealerAssignment::where('level', $d['level'])->where('is_active', true);
            if ($place) {
                $query->where('place_code_id', $place->id);
            } else {
                $query->whereNull('place_code_id');
                foreach ($path as $field => $value) {
                    $query->whereRaw('LOWER(TRIM("'.$field.'")) = ?', [mb_strtolower($value)]);
                }
            }
            if ($query->exists()) {
                throw ValidationException::withMessages(['place_code_id' => 'This territory already has an active dealer. Remove that assignment before replacing it.']);
            }
            $territoryKey = $place
                ? hash('sha256', $d['level'].'|code:'.$place->code)
                : hash('sha256', $d['level'].'|'.mb_strtolower(implode('|', $path)));
            AreaDealerAssignment::create([
                ...collect($d)->only(['user_id', 'level', 'zone_name', 'division', 'district', 'upazila', 'union', 'monthly_quota'])->all(),
                'place_code_id' => $place?->id,
                'territory_key' => $territoryKey,
                'is_active' => true,
            ]);
        }, 3);
        Audit::record($r->user()->id, 'area_dealer.assigned', (string) $d['user_id'], [
            'level' => $d['level'],
            'zone' => $d['zone_name'],
            'place_code' => $place?->code,
        ]);

        return back()->with('success', "Assigned area dealer for {$d['zone_name']}.");
    }

    public function removeAreaDealer(Request $r, int $id): RedirectResponse
    {
        $this->permit($r, 'accounts');
        $assignment = AreaDealerAssignment::findOrFail($id);
        $assignment->update(['is_active' => false, 'territory_key' => null]);
        Audit::record($r->user()->id, 'area_dealer.removed', (string) $assignment->user_id, ['level' => $assignment->level, 'zone' => $assignment->zone_name]);

        return back()->with('success', 'Area dealer assignment removed.');
    }
}
