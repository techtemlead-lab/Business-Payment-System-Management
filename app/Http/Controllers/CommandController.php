<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Accounts\PartnerKycDocuments;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Content\CircularPublishNotifications;
use App\Domain\Finance\PolicyDraftService;
use App\Domain\Inventory\StockService;
use App\Domain\Notifications\PartnerEventSms;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommandController extends Controller
{
    private function permit(Request $r, string $cap): void
    {
        abort_unless($r->user()->allows($cap), 403);
    }

    public function order(StoreOrderRequest $r, OrderService $service): RedirectResponse
    {
        $o = $service->create($r->user(), $r->validated());

        return redirect('/orders/'.$o->id)->with('success', __('portal.flash_order_submitted'));
    }

    public function decision(DecideOrderRequest $r, Order $order, OrderService $s): RedirectResponse
    {
        $d = $r->validated();
        $s->decision($r->user(), $order, $d['status'], $d['reason'], $r->boolean('allow_credit'), $r->replacements());

        return back()->with('success', __('portal.flash_order_decision'));
    }

    public function verify(VerifyPaymentRequest $r, int $claim, OrderService $s): RedirectResponse
    {
        $d = $r->validated();
        $s->verify($r->user(), $claim, $d['approve'], $d['reason']);

        return back()->with('success', __('portal.flash_payment_review'));
    }

    public function dispatch(DispatchOrderRequest $r, Order $order, OrderService $s): RedirectResponse
    {
        $d = $r->validated();
        $s->dispatch($r->user(), $order, $d);

        return back()->with('success', __('portal.flash_shipment_recorded'));
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
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'place_code' => 'required|string|max:32',
                'dealer_code' => 'nullable|string|max:40',
                'reference_code' => 'nullable|string|max:40',
                'refer_code' => 'required|string|max:40',
                'phone' => 'required|string|max:30',
                'email' => 'required|email|max:254',
                'extra_contact' => 'nullable|string|max:80',
                'pharmacy' => 'nullable|string|max:150',
                'shop_name' => 'nullable|string|max:150',
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
            'profile' => 'sometimes|array:name_bn,father_name,mother_name,village,post_office,upazila,union,pharmacy,shop_name,area_code',
            'profile.name_bn' => 'nullable|string|max:150',
            'profile.father_name' => 'nullable|string|max:150',
            'profile.mother_name' => 'nullable|string|max:150',
            'profile.village' => 'nullable|string|max:150',
            'profile.post_office' => 'nullable|string|max:150',
            'profile.upazila' => 'nullable|string|max:150',
            'profile.union' => 'nullable|string|max:150',
            'profile.pharmacy' => 'nullable|string|max:150',
            'profile.shop_name' => 'nullable|string|max:150',
            'profile.area_code' => 'nullable|string|max:80',
        ]);
        $profileKeys = ['name_bn', 'father_name', 'mother_name', 'village', 'post_office', 'upazila', 'union', 'pharmacy', 'shop_name', 'area_code'];
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
        if (! empty($profileData['shop_name']) && empty($profileData['pharmacy'])) {
            $profileData['pharmacy'] = $profileData['shop_name'];
        }
        $d['profile'] = $profileData;
        unset($d['permissions']);
        if (! empty($d['nid'])) {
            $this->permit($r, 'identity_write');
        }
        if (! empty($d['sponsor_id'])) {
            abort_unless(User::whereKey($d['sponsor_id'])->where('role', 'dealer')->where('status', 'active')->exists(), 422, __('portal.cmd_val_sponsor_active_dealer'));
        }
        if (($d['directory_visible'] ?? false) && $d['role'] !== 'dealer') {
            throw ValidationException::withMessages(['directory_visible' => __('portal.cmd_val_directory_dealer_only')]);
        }
        $u = DB::transaction(function () use ($r, $d, $isPartner) {
            CommissionWallet::lock();
            $role = $d['role'];
            $dealerCode = $isPartner ? trim((string) $d['dealer_code']) : ($d['dealer_code'] ?? null);
            $reason = $d['reason'] ?? __('portal.audit_account_created_staff');
            unset($d['reason']);
            $u = (new User)->forceFill([
                ...$d,
                'dealer_code' => $dealerCode,
                'password' => Str::random(48),
            ]);
            $u->save();
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
        $accountTypeLabel = ($d['role'] ?? '') === 'employee'
            ? __('portal.acct_type_employee')
            : __('portal.acct_type_partner');

        return redirect($redirectUrl)->with('success', __('portal.acct_created_flash', [
            'type' => $accountTypeLabel,
            'code' => $u->dealer_code ?? $u->name,
        ]));
    }

    public function accountUpdate(Request $r, User $user, PartnerAccountProvisioning $partners): RedirectResponse
    {
        $this->permit($r, 'accounts');
        abort_if($user->staff() && $r->user()->role !== 'super_admin', 403);
        abort_if($r->user()->id === $user->id, 422, __('portal.cmd_val_self_suspend'));
        if ($r->exists('nid')) {
            $this->permit($r, 'identity_write');
        }
        $isPartner = in_array($user->role, ['dealer', 'sub_dealer'], true);
        $d = $r->validate(['status' => 'sometimes|in:active,suspended', 'name' => 'sometimes|required|string|max:150', 'dealer_code' => 'sometimes|nullable|string|max:40|unique:users,dealer_code,'.$user->id, 'phone' => 'nullable|string|max:30', 'division' => 'nullable|string|max:80', 'district' => 'nullable|string|max:80', 'place_code' => [$isPartner ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:32'], 'latitude' => 'sometimes|nullable|numeric|between:-90,90', 'longitude' => 'sometimes|nullable|numeric|between:-180,180', 'sponsor_id' => 'sometimes|nullable|integer|exists:users,id', 'directory_visible' => 'sometimes|boolean', 'reason' => 'required|string|max:1000',
            'permissions' => [Rule::prohibitedIf($r->user()->role !== 'super_admin' || ! in_array($user->role, ['employee', 'sr'], true)), 'sometimes', 'nullable', 'array'],
            'permissions.*' => ['string', Rule::in(config($user->role === 'sr' ? 'madina.roles.sr' : 'madina.roles.super_admin'))],
            'profile' => 'sometimes|array:name_bn,father_name,mother_name,village,post_office,upazila,union,pharmacy,shop_name,area_code', 'profile.name_bn' => 'nullable|string|max:150', 'profile.father_name' => 'nullable|string|max:150', 'profile.mother_name' => 'nullable|string|max:150', 'profile.village' => 'nullable|string|max:150', 'profile.post_office' => 'nullable|string|max:150', 'profile.upazila' => 'nullable|string|max:150', 'profile.union' => 'nullable|string|max:150', 'profile.pharmacy' => 'nullable|string|max:150', 'profile.shop_name' => 'nullable|string|max:150', 'profile.area_code' => 'nullable|string|max:80', 'nid' => 'sometimes|nullable|string|max:30']);
        if ($r->has('sponsor_id')) {
            $newSponsorId = ! empty($d['sponsor_id']) ? (int) $d['sponsor_id'] : null;
            if ($newSponsorId) {
                if ($newSponsorId === $user->id) {
                    throw ValidationException::withMessages(['sponsor_id' => __('portal.cmd_val_self_sponsor')]);
                }
                abort_unless(User::whereKey($newSponsorId)->where('role', 'dealer')->where('status', 'active')->exists(), 422, __('portal.cmd_val_sponsor_active_dealer'));
                if (ReferralTreePath::isDescendant($user->id, $newSponsorId)) {
                    throw ValidationException::withMessages(['sponsor_id' => __('portal.cmd_val_circular_sponsor')]);
                }
            }
        }
        $profileKeys = ['name_bn', 'father_name', 'mother_name', 'village', 'post_office', 'upazila', 'union', 'pharmacy', 'shop_name', 'area_code'];
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
                    throw ValidationException::withMessages(['permissions' => __('portal.cmd_val_permissions_roles')]);
                }
                $incoming['permissions'] = array_values(array_filter((array) $r->input('permissions')));
            }
            if (! empty($incoming['shop_name']) && empty($incoming['pharmacy'])) {
                $incoming['pharmacy'] = $incoming['shop_name'];
            }
            $d['profile'] = array_merge($existing, $incoming);
        }
        unset($d['permissions']);
        $placeCode = isset($d['place_code']) ? trim((string) $d['place_code']) : '';
        unset($d['place_code']);
        if ($isPartner && $placeCode !== '') {
            // Catalog place code is the geography source of truth — ignore free-text geo overrides on the same request.
            unset($d['division'], $d['district']);
            if (isset($d['profile']) && is_array($d['profile'])) {
                unset($d['profile']['upazila'], $d['profile']['union'], $d['profile']['area_code']);
            }
        }
        $statusChange = null;
        $sponsorChange = null;
        $directoryChange = null;
        DB::transaction(function () use ($r, $user, $d, $partners, $isPartner, $placeCode, &$statusChange, &$sponsorChange, &$directoryChange) {
            CommissionWallet::lock();
            $reason = $d['reason'];
            unset($d['reason']);
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $previousStatus = (string) $locked->status;
            $previousSponsorId = $locked->sponsor_id !== null ? (int) $locked->sponsor_id : null;
            $previousDirectoryVisible = (bool) $locked->directory_visible;

            if (array_key_exists('sponsor_id', $d) && (int) $locked->sponsor_id !== (int) ($d['sponsor_id'] ?? null)) {
                ReferralTreePath::reparentUser($locked->id, ! empty($d['sponsor_id']) ? (int) $d['sponsor_id'] : null);
            }

            // Privilege fields are guarded on User; forceFill only after accounts authorization above.
            $locked->forceFill($d)->save();
            if ($isPartner && $placeCode !== '') {
                $partners->rebindGeography($r->user(), $locked->fresh(), $placeCode, $reason);
            }
            if (($d['status'] ?? '') === 'suspended') {
                $locked->forceFill(['remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            Audit::record($r->user()->id, 'account.updated', (string) $user->id, ['fields' => array_keys($d), 'reason' => $reason]);
            if ($isPartner && array_key_exists('status', $d) && $previousStatus !== (string) $d['status']) {
                $statusChange = [
                    'from' => $previousStatus,
                    'to' => (string) $d['status'],
                    'reason' => $reason,
                    'user_id' => $locked->id,
                ];
            }
            if ($isPartner && array_key_exists('sponsor_id', $d)) {
                $nextSponsorId = ! empty($d['sponsor_id']) ? (int) $d['sponsor_id'] : null;
                if ($previousSponsorId !== $nextSponsorId) {
                    $sponsorChange = [
                        'from' => $previousSponsorId,
                        'to' => $nextSponsorId,
                        'reason' => $reason,
                        'user_id' => $locked->id,
                    ];
                }
            }
            if ($isPartner && $locked->role === 'dealer' && array_key_exists('directory_visible', $d)) {
                $nextDirectoryVisible = (bool) $d['directory_visible'];
                if ($previousDirectoryVisible !== $nextDirectoryVisible) {
                    $directoryChange = [
                        'visible' => $nextDirectoryVisible,
                        'reason' => $reason,
                        'user_id' => $locked->id,
                    ];
                }
            }
        });
        if (is_array($statusChange)) {
            $partner = User::find($statusChange['user_id']);
            if ($partner) {
                $locale = $partner->preferredLocale();
                if ($statusChange['to'] === 'suspended') {
                    Audit::notice(
                        $partner->id,
                        __('portal.notice_account_suspended_title', [], $locale),
                        __('portal.notice_account_suspended_body', ['reason' => $statusChange['reason']], $locale),
                    );
                    app(PartnerEventSms::class)->send(
                        $partner,
                        'account.suspended',
                        __('sms.account_suspended', ['reason' => $statusChange['reason']], $locale),
                    );
                } elseif ($statusChange['to'] === 'active' && $statusChange['from'] === 'suspended') {
                    Audit::notice(
                        $partner->id,
                        __('portal.notice_account_reactivated_title', [], $locale),
                        __('portal.notice_account_reactivated_body', [], $locale),
                    );
                    app(PartnerEventSms::class)->send(
                        $partner,
                        'account.reactivated',
                        __('sms.account_reactivated', [], $locale),
                    );
                }
            }
        }
        if (is_array($sponsorChange)) {
            $partner = User::find($sponsorChange['user_id']);
            if ($partner) {
                $locale = $partner->preferredLocale();
                $sponsor = $sponsorChange['to'] !== null ? User::find($sponsorChange['to']) : null;
                $sponsorLabel = $sponsor
                    ? trim(($sponsor->dealer_code ? $sponsor->dealer_code.' · ' : '').$sponsor->name)
                    : __('portal.sponsor_none', [], $locale);
                Audit::notice(
                    $partner->id,
                    __('portal.notice_sponsor_updated_title', [], $locale),
                    __('portal.notice_sponsor_updated_body', [
                        'sponsor' => $sponsorLabel,
                        'reason' => $sponsorChange['reason'],
                    ], $locale),
                );
                app(PartnerEventSms::class)->send(
                    $partner,
                    'account.sponsor_updated',
                    __('sms.sponsor_updated', [
                        'sponsor' => $sponsorLabel,
                        'reason' => $sponsorChange['reason'],
                    ], $locale),
                );
            }
        }
        if (is_array($directoryChange)) {
            $partner = User::find($directoryChange['user_id']);
            if ($partner) {
                $locale = $partner->preferredLocale();
                $visible = (bool) $directoryChange['visible'];
                Audit::notice(
                    $partner->id,
                    __(
                        $visible ? 'portal.notice_directory_listed_title' : 'portal.notice_directory_unlisted_title',
                        [],
                        $locale,
                    ),
                    __(
                        $visible ? 'portal.notice_directory_listed_body' : 'portal.notice_directory_unlisted_body',
                        ['reason' => $directoryChange['reason']],
                        $locale,
                    ),
                );
                app(PartnerEventSms::class)->send(
                    $partner,
                    $visible ? 'account.directory_listed' : 'account.directory_unlisted',
                    __(
                        $visible ? 'sms.directory_listed' : 'sms.directory_unlisted',
                        [],
                        $locale,
                    ),
                );
            }
        }

        return back()->with('success', __('portal.flash_account_updated'));
    }

    public function benefitControls(Request $r, User $user, PartnerBenefitControls $controls): RedirectResponse
    {
        abort_unless(
            $r->user()->canAct('finance', 'approve') || $r->user()->role === 'super_admin',
            403,
            __('portal.pbc_finance_required'),
        );
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 422, __('portal.pbc_partners_only'));
        $d = $r->validate([
            'unconditional_salary' => 'sometimes|boolean',
            'unconditional_rank_royalty' => 'sometimes|boolean',
            'forced_royalty_rank' => 'nullable|in:mo,am,sm,me',
            'unconditional_rank_incentive' => 'sometimes|boolean',
            'note' => 'nullable|string|max:500',
            'reason' => 'required|string|min:5|max:1000',
        ]);
        $controls->save($r->user(), $user, [
            'unconditional_salary' => $r->boolean('unconditional_salary'),
            'unconditional_rank_royalty' => $r->boolean('unconditional_rank_royalty'),
            'forced_royalty_rank' => $d['forced_royalty_rank'] ?? 'mo',
            'unconditional_rank_incentive' => $r->boolean('unconditional_rank_incentive'),
            'note' => $d['note'] ?? null,
            'reason' => $d['reason'],
        ]);

        return back()->with('success', __('portal.pbc_saved', ['name' => $user->name]));
    }

    public function product(Request $r): RedirectResponse
    {
        $this->permit($r, 'catalog');
        abort_unless($r->user()->role === 'super_admin', 403);
        $d = $r->validate(['name' => 'required|string|max:150', 'sku' => 'required|string|max:60|unique:products', 'category' => 'required|string|max:100', 'description' => 'nullable|string|max:3000',
            'mrp' => 'required|regex:/^\d{1,8}(\.\d{1,2})?$/', 'dealer_price' => 'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/', 'unit' => 'required|string|max:30']);
        if (Money::cents($d['mrp']) <= 0 || (isset($d['dealer_price']) && (Money::cents($d['dealer_price']) <= 0 || Money::cents($d['dealer_price']) > Money::cents($d['mrp'])))) {
            throw ValidationException::withMessages(['mrp' => __('portal.cmd_val_mrp_prices')]);
        }
        DB::transaction(function () use ($r, $d) {
            $p = Product::create($d);
            Audit::record($r->user()->id, 'product.created', (string) $p->id);
        });

        return back()->with('success', __('portal.flash_product_created'));
    }

    public function stock(AdjustStockRequest $r, StockService $service): RedirectResponse
    {
        $service->adjust($r->user(), $r->validated());

        return back()->with('success', __('portal.flash_stock_recorded'));
    }

    public function policy(Request $r, PolicyDraftService $service): RedirectResponse
    {
        $this->permit($r, 'finance');
        $d = $r->validate(['name' => 'required|string|max:150', 'account_type' => 'required|in:dealer,sub_dealer', 'components' => 'required|array|min:1|max:20', 'components.*.name' => 'required|string|max:80', 'components.*.rate' => 'required|numeric|min:0|max:100', 'notes' => 'required|string|max:4000']);
        $service->create($r->user(), $d);

        return back()->with('success', __('portal.flash_policy_draft_saved'));
    }

    public function content(Request $r, CircularPublishNotifications $circularNotices): RedirectResponse
    {
        $this->permit($r, 'content');
        $d = $r->validate([
            'slug' => 'required|in:home,about,contact,notice,circular-training,circular-price,circular-meeting,partnership,services,quality,faq,privacy,terms,products,dealers',
            'title' => 'required|string|max:150',
            'body' => 'required|string|max:10000',
            'published' => 'required|boolean',
        ]);
        DB::transaction(function () use ($r, $d, $circularNotices) {
            $existing = DB::table('content_pages')->where('slug', $d['slug'])->first();
            $wasPublished = $existing !== null && (bool) $existing->published;
            DB::table('content_pages')->updateOrInsert(
                ['slug' => $d['slug']],
                [...$d, 'updated_at' => now(), ...($existing ? [] : ['created_at' => now()])]
            );
            Audit::record($r->user()->id, 'content.updated', $d['slug']);
            $circularNotices->notifyIfNewlyPublished(
                $d['slug'],
                $d['title'],
                (bool) $d['published'],
                $wasPublished,
            );
        });

        return back()->with('success', __('portal.flash_content_saved'));
    }

    public function publishPolicy(Request $r, int $id, PolicyDraftService $service): RedirectResponse
    {
        $this->permit($r, 'finance');
        $service->publish($r->user(), $id);

        return back()->with('success', __('portal.flash_policy_published'));
    }

    public function customization(Request $r): RedirectResponse
    {
        abort_unless($r->user()->allows('content') || $r->user()->allows('catalog') || $r->user()->role === 'super_admin', 403);
        $payload = $r->validate(['customization' => 'required|array'])['customization'];
        SiteCustomization::save($payload);
        Audit::record($r->user()->id, 'customization.updated', 'frontend');

        return back()->with('success', __('portal.flash_customization_saved'));
    }

    public function resetCustomization(Request $r): RedirectResponse
    {
        $this->permit($r, 'content');
        SiteCustomization::reset();
        Audit::record($r->user()->id, 'customization.reset', 'frontend');

        return back()->with('success', __('portal.flash_customization_reset'));
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

        return back()->with('success', __('portal.flash_dashboard_saved'));
    }

    public function resetDashboardSettings(Request $r): RedirectResponse
    {
        abort_unless($r->user()->role === 'super_admin' || $r->user()->allows('accounts'), 403);
        DashboardManagement::reset();
        Audit::record($r->user()->id, 'dashboard.reset', 'control_center');

        return back()->with('success', __('portal.flash_dashboard_reset'));
    }

    public function enquiry(Request $r, int $id): RedirectResponse
    {
        abort_unless($r->user()->allows('content') || $r->user()->allows('accounts'), 403);
        $d = $r->validate(['status' => 'required|in:new,contacted,closed']);
        $enquiry = DB::table('enquiries')->where('id', $id)->first();
        abort_unless($enquiry, 404);
        $previous = (string) $enquiry->status;
        DB::table('enquiries')->where('id', $id)->update([...$d, 'updated_at' => now()]);
        Audit::record($r->user()->id, 'enquiry.updated', (string) $id);
        if ($previous !== $d['status'] && in_array($d['status'], ['contacted', 'closed'], true)) {
            $supported = config('madina.locales', ['en', 'bn']);
            $locale = is_string($enquiry->locale ?? null) && in_array($enquiry->locale, $supported, true)
                ? $enquiry->locale
                : app()->getLocale();
            $topicLabel = match ((string) $enquiry->topic) {
                'dealer_enquiry' => __('portal.enquiry_topic_dealer', [], $locale),
                'partnership_enquiry', 'Partnership' => __('portal.enquiry_topic_partnership', [], $locale),
                'product_enquiry' => __('portal.enquiry_topic_product', [], $locale),
                'support' => __('portal.support_topic_support', [], $locale),
                'complaint' => __('portal.support_topic_complaint', [], $locale),
                'order_dispute' => __('portal.support_topic_order', [], $locale),
                'kyc_correction' => __('portal.support_topic_kyc', [], $locale),
                'payout_question' => __('portal.support_topic_payout', [], $locale),
                default => (string) $enquiry->topic,
            };
            $statusLabel = __('portal.enquiry_status_'.$d['status'], [], $locale);
            $template = $d['status'] === 'contacted'
                ? 'sms.enquiry_follow_up_contacted'
                : 'sms.enquiry_follow_up_closed';
            app(PartnerEventSms::class)->sendToPhone(
                (string) $enquiry->phone,
                'enquiry.'.$d['status'],
                __($template, [
                    'topic' => $topicLabel,
                    'status' => $statusLabel,
                ], $locale),
            );
        }

        return back()->with('success', __('portal.flash_enquiry_updated'));
    }

    public function readNotice(Request $r, int $id): RedirectResponse
    {
        abort_unless(DB::table('account_notices')->where('id', $id)->where('user_id', $r->user()->id)->update(['read_at' => now()]), 404);

        return back();
    }

    public function readAllNotices(Request $r): RedirectResponse
    {
        DB::table('account_notices')
            ->where('user_id', $r->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return back()->with('success', __('portal.flash_notices_read'));
    }

    public function partnerSupport(Request $r): RedirectResponse
    {
        $user = $r->user();
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);
        $data = $r->validate([
            'topic' => ['required', Rule::in([
                'support', 'complaint', 'order_dispute', 'kyc_correction', 'payout_question',
                // Legacy English values still accepted from older clients.
                'Support', 'Complaint', 'Order dispute', 'KYC correction', 'Payout question',
            ])],
            'message' => 'required|string|min:10|max:2000',
        ]);
        $topicKey = match ($data['topic']) {
            'Support', 'support' => 'support',
            'Complaint', 'complaint' => 'complaint',
            'Order dispute', 'order_dispute' => 'order_dispute',
            'KYC correction', 'kyc_correction' => 'kyc_correction',
            'Payout question', 'payout_question' => 'payout_question',
            default => strtolower((string) $data['topic']),
        };
        $locale = is_string($user->locale ?? null) && in_array($user->locale, config('madina.locales', ['en', 'bn']), true)
            ? $user->locale
            : app()->getLocale();
        DB::table('enquiries')->insert([
            'name' => $user->name,
            'phone' => $user->phone ?: ($user->dealer_code ?: 'partner-'.$user->id),
            'topic' => $topicKey,
            'message' => '['.($user->dealer_code ?: $user->email).'] '.$data['message'],
            'status' => 'new',
            'locale' => $locale,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Audit::record($user->id, 'enquiry.partner_support', (string) $user->id, [
            'topic' => $topicKey,
        ]);
        $topicLabel = match ($topicKey) {
            'support' => __('portal.support_topic_support', [], $locale),
            'complaint' => __('portal.support_topic_complaint', [], $locale),
            'order_dispute' => __('portal.support_topic_order', [], $locale),
            'kyc_correction' => __('portal.support_topic_kyc', [], $locale),
            'payout_question' => __('portal.support_topic_payout', [], $locale),
            default => $topicKey,
        };
        app(PartnerEventSms::class)->send(
            $user,
            'support.requested',
            __('sms.support_requested', ['topic' => $topicLabel], $locale),
        );

        return back()->with('success', __('portal.flash_support_sent'));
    }

    public function financialGate(): never
    {
        throw ValidationException::withMessages(['policy' => __('portal.cmd_val_financial_gate')]);
    }

    public function claim(ClaimPaymentRequest $r, Order $order, OrderService $service): RedirectResponse
    {
        $data = $r->validated();
        $service->claim($r->user(), $order, $data);

        return back()->with('success', __('portal.flash_payment_claim'));
    }

    public function delivered(ConfirmDeliveryRequest $r, Order $order, int $shipment, OrderService $service): RedirectResponse
    {
        $data = $r->validated();
        $service->deliver($r->user(), $order, $shipment, $data['reason']);

        return back()->with('success', __('portal.flash_delivery_recorded'));
    }

    public function invite(Request $r, User $user): RedirectResponse
    {
        $this->permit($r, 'accounts');
        abort_if($user->staff() && $r->user()->role !== 'super_admin', 403);
        abort_unless($user->status === 'active', 422);
        Password::sendResetLink(['email' => $user->email]);
        Audit::record($r->user()->id, 'account.invitation_sent', (string) $user->id);
        $locale = $user->preferredLocale();
        Audit::notice(
            $user->id,
            __('portal.notice_account_invite_title', [], $locale),
            __('portal.notice_account_invite_body', [], $locale),
        );
        app(PartnerEventSms::class)->send(
            $user,
            'account.invitation_sent',
            __('sms.account_invitation_sent', [], $locale),
        );

        return back()->with('success', __('portal.acct_invite_flash'));
    }

    public function kycDocuments(Request $r, User $user, PartnerKycDocuments $documents): JsonResponse
    {
        $this->permit($r, 'identity_write');
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 404);

        return response()->json(['data' => $documents->listFor($user)]);
    }

    public function storeKycDocument(Request $r, User $user, PartnerKycDocuments $documents): RedirectResponse
    {
        $this->permit($r, 'identity_write');
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 404);
        $data = $r->validate([
            'kind' => ['required', Rule::in(PartnerKycDocuments::Kinds)],
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            'note' => 'nullable|string|max:500',
        ]);
        $stored = $documents->store($r->user(), $user, $data['kind'], $r->file('document'), $data['note'] ?? null);

        return back()->with('success', __('portal.flash_kyc_stored', ['kind' => $stored['kind']]));
    }

    public function downloadKycDocument(Request $r, User $user, string $document, PartnerKycDocuments $documents): StreamedResponse
    {
        $this->permit($r, 'identity_write');
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 404);

        return $documents->download($user, $document);
    }

    public function updateCommissionRules(Request $r, CommissionPolicy $policy): RedirectResponse
    {
        $this->permit($r, 'finance');
        $data = $r->validate(['rules' => 'required|array']);
        $policy->update($r->user(), $data['rules']);

        return back()->with('success', __('portal.flash_commission_policy_updated'));
    }

    public function settleSalaryPool(Request $r, CommissionService $commissionService): RedirectResponse
    {
        $this->permit($r, 'finance');
        $d = $r->validate(['period_month' => 'required|date_format:Y-m', 'request_key' => 'required|uuid']);
        $result = $commissionService->settleMonthlySalaryPool($d['period_month'], $r->user()->id, $d['request_key']);

        if ($result['status'] === 'empty_pool') {
            return back()->with('success', __('portal.flash_salary_pool_empty', ['period' => $d['period_month']]));
        }
        if ($result['status'] === 'rolled_over') {
            return back()->with('success', __('portal.flash_salary_pool_rolled', [
                'period' => $d['period_month'],
                'amount' => $result['total_rolled_to_reserve'],
            ]));
        }

        $rolled = $result['total_rolled_to_reserve'] ?? '0.00';
        $extra = Money::cents((string) $rolled) > 0
            ? __('portal.flash_salary_pool_reserve_extra', ['amount' => $rolled])
            : '';

        return back()->with('success', __('portal.flash_salary_pool_distributed', [
            'period' => $d['period_month'],
            'amount' => $result['total_distributed'],
            'count' => $result['qualifiers_count'],
        ]).$extra);
    }

    public function settleRankRoyaltyPool(Request $r, CommissionService $commissionService): RedirectResponse
    {
        $this->permit($r, 'finance');
        $d = $r->validate(['period_month' => 'required|date_format:Y-m', 'request_key' => 'required|uuid']);
        $result = $commissionService->settleRankRoyaltyPool($d['period_month'], $r->user()->id, $d['request_key']);

        if ($result['status'] === 'empty_pool') {
            return back()->with('success', __('portal.flash_royalty_pool_empty', ['period' => $d['period_month']]));
        }

        $rolled = $result['total_rolled_to_reserve'] ?? '0.00';
        $extra = Money::cents((string) $rolled) > 0
            ? __('portal.flash_royalty_pool_reserve_extra', ['amount' => $rolled])
            : '';

        return back()->with('success', __('portal.flash_royalty_pool_distributed', [
            'period' => $d['period_month'],
            'amount' => $result['total_distributed'],
        ]).$extra);
    }

    public function claimRankIncentive(Request $r, User $user, CommissionService $commissionService): RedirectResponse
    {
        $this->permit($r, 'finance');
        $d = $r->validate(['request_key' => 'required|uuid']);
        $result = $commissionService->claimOneTimeRankIncentive($user->id, actorId: $r->user()->id, requestKey: $d['request_key']);

        if (! $result['success']) {
            throw ValidationException::withMessages(['incentive' => $result['message']]);
        }

        return back()->with('success', __('portal.flash_rank_incentive_paid', [
            'amount' => $result['amount'],
            'name' => $user->name,
            'rank' => strtoupper($result['rank']),
        ]));
    }

    public function releaseMaturedCommissions(Request $r, CommissionService $commissionService): RedirectResponse
    {
        $this->permit($r, 'finance');
        $d = $r->validate(['request_key' => 'required|uuid']);
        $result = $commissionService->releaseMaturedCommissions($r->user()->id, $d['request_key']);

        if ($result['status'] === 'no_matured_commissions') {
            return back()->with('success', __('portal.flash_commissions_none_matured'));
        }

        return back()->with('success', __('portal.flash_commissions_released', [
            'count' => $result['released_count'],
            'amount' => $result['total_amount'],
        ]));
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
                throw ValidationException::withMessages(['place_code_id' => __('portal.cmd_val_place_level_mismatch')]);
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
                    throw ValidationException::withMessages(['place_code_id' => __('portal.cmd_val_place_hierarchy')]);
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
                    throw ValidationException::withMessages([$field => __('portal.cmd_val_territory_required')]);
                }
                $d[$field] = trim($d[$field]);
                $path[$field] = $d[$field];
                if ($field === $d['level']) {
                    break;
                }
            }
            if (trim((string) ($d['zone_name'] ?? '')) === '') {
                throw ValidationException::withMessages(['zone_name' => __('portal.cmd_val_zone_name_required')]);
            }
        }

        DB::transaction(function () use ($d, $path, $place): void {
            CommissionWallet::lock();
            $user = User::whereKey($d['user_id'])->lockForUpdate()->firstOrFail();
            if ($user->role !== 'dealer' || $user->status !== 'active') {
                throw ValidationException::withMessages(['user_id' => __('portal.cmd_val_active_dealer')]);
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
                throw ValidationException::withMessages(['place_code_id' => __('portal.cmd_val_territory_taken')]);
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
        $dealer = User::find($d['user_id']);
        if ($dealer) {
            $locale = $dealer->preferredLocale();
            Audit::notice(
                $dealer->id,
                __('portal.notice_area_dealer_assigned_title', [], $locale),
                __('portal.notice_area_dealer_assigned_body', [
                    'zone' => $d['zone_name'],
                    'quota' => $d['monthly_quota'],
                ], $locale),
            );
            app(PartnerEventSms::class)->send(
                $dealer,
                'area_dealer.assigned',
                __('sms.area_dealer_assigned', [
                    'zone' => $d['zone_name'],
                    'quota' => $d['monthly_quota'],
                ], $locale),
            );
        }

        return back()->with('success', __('portal.flash_area_dealer_assigned', ['zone' => $d['zone_name']]));
    }

    public function removeAreaDealer(Request $r, int $id): RedirectResponse
    {
        $this->permit($r, 'accounts');
        $assignment = AreaDealerAssignment::findOrFail($id);
        $assignment->update(['is_active' => false, 'territory_key' => null]);
        Audit::record($r->user()->id, 'area_dealer.removed', (string) $assignment->user_id, ['level' => $assignment->level, 'zone' => $assignment->zone_name]);
        $dealer = User::find($assignment->user_id);
        if ($dealer) {
            $locale = $dealer->preferredLocale();
            Audit::notice(
                $dealer->id,
                __('portal.notice_area_dealer_removed_title', [], $locale),
                __('portal.notice_area_dealer_removed_body', [
                    'zone' => $assignment->zone_name,
                ], $locale),
            );
            app(PartnerEventSms::class)->send(
                $dealer,
                'area_dealer.removed',
                __('sms.area_dealer_removed', [
                    'zone' => $assignment->zone_name,
                ], $locale),
            );
        }

        return back()->with('success', __('portal.flash_area_dealer_removed'));
    }
}
