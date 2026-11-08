<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountPhoneRules;
use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Accounts\PartnerAge;
use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Accounts\PartnerCreateMedia;
use App\Domain\Accounts\PartnerKycDocuments;
use App\Domain\Accounts\PasswordAccess;
use App\Domain\Accounts\PasswordResetOwnerNotice;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Content\CircularPublishNotifications;
use App\Domain\Content\TestimonialLabels;
use App\Domain\Finance\PolicyDraftService;
use App\Domain\Geography\ManagementReferral;
use App\Domain\Geography\PlaceTree;
use App\Domain\Inventory\StockService;
use App\Domain\Notifications\AccountCreatedSms;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Domain\Orders\OrderRefund;
use App\Domain\Orders\OrderService;
use App\Domain\Staff\PartnerStaffLink;
use App\Domain\Staff\StaffPermissions;
use App\Domain\Support\SupportTickets;
use App\Domain\Support\TourGuide;
use App\Http\Requests\Backend\AdjustStockRequest;
use App\Http\Requests\Backend\ClaimPaymentRequest;
use App\Http\Requests\Backend\ConfirmDeliveryRequest;
use App\Http\Requests\Backend\DecideOrderRequest;
use App\Http\Requests\Backend\DispatchOrderRequest;
use App\Http\Requests\Backend\RefundOrderRequest;
use App\Http\Requests\Backend\StoreOrderRequest;
use App\Http\Requests\Backend\VerifyPaymentRequest;
use App\Models\AreaDealerAssignment;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\SiteFeedback;
use App\Models\User;
use App\Support\Audit;
use App\Support\DashboardManagement;
use App\Support\Money;
use App\Support\PhoneIdentity;
use App\Support\SiteCustomization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        $s->decision($r->user(), $order, $d['status'], $d['reason'], $r->replacements());

        return back(fallback: '/orders/'.$order->id)->with('success', __('portal.flash_order_decision'));
    }

    /** Send a paid, not-yet-dispatched order's money back to the dealer and reverse its commission. */
    public function refund(RefundOrderRequest $r, Order $order, OrderRefund $refunds): RedirectResponse
    {
        $d = $r->validated();
        $refunds->refund($r->user(), $order, (string) $d['amount'], $d['reason']);

        return back(fallback: '/orders/'.$order->id)->with('success', __('portal.flash_order_refunded'));
    }

    public function verify(VerifyPaymentRequest $r, int $claim, OrderService $s): RedirectResponse
    {
        $d = $r->validated();
        $claimRow = DB::table('payment_claims')->find($claim);
        $s->verify($r->user(), $claim, $d['approve'], $d['reason']);

        return back(fallback: $claimRow ? '/orders/'.$claimRow->order_id : '/payments')->with('success', __('portal.flash_payment_review'));
    }

    public function dispatch(DispatchOrderRequest $r, Order $order, OrderService $s): RedirectResponse
    {
        $d = $r->validated();
        $s->dispatch($r->user(), $order, $d);

        return back(fallback: '/orders/'.$order->id)->with('success', __('portal.flash_shipment_recorded'));
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
                'date_of_birth' => ['required', 'date', PartnerAge::rule()],
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
                'refer_code' => 'nullable|string|max:40',
                'phone' => 'required|string|max:30',
                'email' => 'required|email|max:254',
                'phone_share_reason' => 'nullable|string|max:500',
                'extra_contact' => 'nullable|string|max:80',
                'pharmacy' => 'nullable|string|max:150',
                'shop_name' => 'nullable|string|max:150',
                'directory_visible' => 'sometimes|boolean',
                'status' => 'sometimes|in:active,suspended',
                'reason' => 'nullable|string|max:1000',
            ]);
            $created = $partners->create($r->user(), $payload);
            $media = [];
            foreach (PartnerKycDocuments::Kinds as $kind) {
                if ($r->hasFile('kyc_'.$kind)) {
                    $media[$kind] = $r->file('kyc_'.$kind);
                }
            }
            if ($media !== []) {
                $r->validate([
                    'kyc_photo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
                    'kyc_nid_front' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
                    'kyc_nid_back' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
                    'kyc_birth_certificate' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
                    'kyc_agreement' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
                    'kyc_other' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
                ]);
                app(PartnerCreateMedia::class)->attachOptional($r->user(), $created['user'], $media);
            }

            $redirect = $created['user']->role === 'sub_dealer' ? '/sub-dealers' : '/accounts';

            return redirect($redirect)->with(
                'success',
                ucfirst(str_replace('_', '-', $created['user']->role)).' account created. Code: '.$created['user']->dealer_code.
                '. Login credentials delivered to '.$created['user']->phone.' / '.$created['user']->email.' (username = mobile number).'
            );
        }

        $d = $r->validate([
            'role' => ['required', Rule::in($roles)],
            'dealer_code' => $isPartner ? ['required', 'string', 'max:40', 'unique:users,dealer_code'] : ['nullable', 'string', 'max:40', 'unique:users,dealer_code'],
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:254|unique:users,email',
            'phone' => 'required|string|max:30',
            'phone_share_reason' => 'nullable|string|max:500',
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
        $phoneCheck = app(AccountPhoneRules::class)->check($r->user(), $d['phone'], null, $d['phone_share_reason'] ?? null);
        unset($d['phone_share_reason']);
        $d = [...$d, ...$phoneCheck];
        if (! empty($d['nid'])) {
            $this->permit($r, 'identity_write');
        }
        if (! empty($d['sponsor_id'])) {
            abort_unless(User::whereKey($d['sponsor_id'])->where('role', 'dealer')->where('status', 'active')->exists(), 422, __('portal.cmd_val_sponsor_active_dealer'));
        }
        if (($d['directory_visible'] ?? false) && $d['role'] !== 'dealer') {
            throw ValidationException::withMessages(['directory_visible' => __('portal.cmd_val_directory_dealer_only')]);
        }
        $u = DB::transaction(function () use ($r, $d, $isPartner, $phoneCheck) {
            CommissionWallet::lock();
            $role = $d['role'];
            $dealerCode = $isPartner ? trim((string) $d['dealer_code']) : ($d['dealer_code'] ?? null);
            $reason = $d['reason'] ?? __('portal.audit_account_created_staff');
            unset($d['reason']);
            $plainPassword = 'Md'.Str::upper(Str::random(2)).random_int(100000, 999999).'!';
            $u = (new User)->forceFill([
                ...$d,
                'dealer_code' => $dealerCode,
                'password' => $plainPassword,
                'password_managed_by' => User::PasswordManagedByAdmin,
                'admin_password_cipher' => $plainPassword,
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
            app(AccountPhoneRules::class)->recordShare($r->user(), $u, $phoneCheck);

            return $u;
        });
        if ($isPartner && $u->sponsor_id === null) {
            app(ManagementReferral::class)->stamp($u);
        }
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
        $d = $r->validate(['status' => 'sometimes|in:active,suspended', 'name' => 'sometimes|required|string|max:150', 'dealer_code' => 'sometimes|nullable|string|max:40|unique:users,dealer_code,'.$user->id, 'phone' => 'sometimes|required|string|max:30', 'phone_share_reason' => 'sometimes|nullable|string|max:500', 'division' => 'nullable|string|max:80', 'district' => 'nullable|string|max:80', 'place_code' => [$isPartner ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:32'], 'latitude' => 'sometimes|nullable|numeric|between:-90,90', 'longitude' => 'sometimes|nullable|numeric|between:-180,180', 'sponsor_id' => 'sometimes|nullable|integer|exists:users,id', 'directory_visible' => 'sometimes|boolean', 'reason' => 'required|string|max:1000',
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
        $phoneCheck = null;
        if (array_key_exists('phone', $d)) {
            $phones = app(AccountPhoneRules::class);
            $newPhone = $phones->normalize($d['phone']);
            if (PhoneIdentity::normalize($newPhone) === PhoneIdentity::normalize((string) $user->phone)) {
                $d['phone'] = $newPhone;
            } else {
                $phoneCheck = $phones->check($r->user(), $newPhone, $user->id, $d['phone_share_reason'] ?? null);
                $d = [...$d, ...$phoneCheck];
            }
        }
        unset($d['phone_share_reason']);
        $placeCode = isset($d['place_code']) ? trim((string) $d['place_code']) : '';
        unset($d['place_code']);
        if ($isPartner) {
            // A partner's place names always come from their place code (changed only through
            // the place code below), so free-text place names are never saved for partners.
            unset($d['division'], $d['district']);
            if (isset($d['profile']) && is_array($d['profile'])) {
                foreach (['upazila', 'union', 'area_code'] as $placeKey) {
                    $saved = data_get($user->profile, $placeKey);
                    if ($saved !== null) {
                        $d['profile'][$placeKey] = $saved;
                    } else {
                        unset($d['profile'][$placeKey]);
                    }
                }
            }
        }
        $statusChange = null;
        $sponsorChange = null;
        $directoryChange = null;
        $phoneChange = null;
        DB::transaction(function () use ($r, $user, $d, $partners, $isPartner, $placeCode, &$statusChange, &$sponsorChange, &$directoryChange, &$phoneChange, $phoneCheck) {
            CommissionWallet::lock();
            $reason = $d['reason'];
            unset($d['reason']);
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $previousStatus = (string) $locked->status;
            $previousSponsorId = $locked->sponsor_id !== null ? (int) $locked->sponsor_id : null;
            $previousDirectoryVisible = (bool) $locked->directory_visible;
            $previousPhone = (string) $locked->phone;
            if ($isPartner && array_key_exists('phone', $d) && $previousPhone !== '' && PhoneIdentity::normalize($previousPhone) !== PhoneIdentity::normalize((string) $d['phone'])) {
                $phoneChange = ['old_phone' => $previousPhone, 'user_id' => $locked->id];
            }

            if (array_key_exists('sponsor_id', $d) && (int) $locked->sponsor_id !== (int) ($d['sponsor_id'] ?? null)) {
                ReferralTreePath::reparentUser($locked->id, ! empty($d['sponsor_id']) ? (int) $d['sponsor_id'] : null);
            }

            // Contact, login and placement fields decide where codes go and who earns
            // from this account, so the audit keeps their old and new values.
            $traceable = ['name', 'phone', 'email', 'status', 'sponsor_id', 'dealer_code', 'refer_code', 'division', 'district', 'directory_visible'];
            $tracedBefore = $locked->only($traceable);

            // Privilege fields are guarded on User; forceFill only after accounts authorization above.
            $locked->forceFill($d)->save();
            $tracedChanges = [];
            foreach ($traceable as $field) {
                if (array_key_exists($field, $d) && $tracedBefore[$field] != $locked->{$field}) {
                    $tracedChanges[$field] = ['before' => $tracedBefore[$field], 'after' => $locked->{$field}];
                }
            }
            if ($isPartner && $placeCode !== '') {
                $partners->rebindGeography($r->user(), $locked->fresh(), $placeCode, $reason);
            }
            if ($isPartner && array_key_exists('sponsor_id', $d)) {
                app(ManagementReferral::class)->stamp($locked->fresh());
            }
            if (($d['status'] ?? '') === 'suspended') {
                $locked->forceFill(['remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            Audit::record($r->user()->id, 'account.updated', (string) $user->id, ['fields' => array_keys($d), 'changes' => $tracedChanges, 'reason' => $reason]);
            if ($phoneCheck !== null) {
                app(AccountPhoneRules::class)->recordShare($r->user(), $locked, $phoneCheck);
            }
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
        if (is_array($phoneChange)) {
            // Tell the old number, so a dealer notices at once if someone moved their account to another phone.
            $partner = User::find($phoneChange['user_id']);
            if ($partner) {
                app(PartnerEventSms::class)->sendToPhone(
                    $phoneChange['old_phone'],
                    'account.phone_changed',
                    SmsText::make('sms.account_phone_changed', ['code' => $partner->dealer_code ?? $partner->name]),
                );
            }
        }
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
                        SmsText::make('sms.account_suspended', ['reason' => $statusChange['reason']]),
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
                        SmsText::make('sms.account_reactivated', []),
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
                    SmsText::make('sms.sponsor_updated', [
                        'sponsor' => $sponsor ? $sponsorLabel : SmsText::make('portal.sponsor_none'),
                        'reason' => $sponsorChange['reason'],
                    ]),
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
                    SmsText::make(
                        $visible ? 'sms.directory_listed' : 'sms.directory_unlisted',
                        [],
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
            'mrp' => 'required|regex:/^\d{1,8}(\.\d{1,2})?$/D', 'dealer_price' => 'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/D', 'unit' => 'required|string|max:30']);
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
        $payload = $r->validated();
        $service->adjust($r->user(), $payload);
        $kind = $payload['kind'] ?? null;
        $flash = match ($kind) {
            'product_in' => __('portal.flash_stock_in_recorded'),
            'product_out' => __('portal.flash_stock_out_recorded'),
            default => __('portal.flash_stock_recorded'),
        };

        return redirect('/inventory')->with('success', $flash);
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

    public function uploadCategoryIcon(Request $r): JsonResponse
    {
        abort_unless($r->user()->allows('content') || $r->user()->allows('catalog') || $r->user()->role === 'super_admin', 403);

        $data = $r->validate([
            'image' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=2048,max_height=2048',
            // Website photos (such as the Managing Director's photo) are kept apart from category icons.
            'purpose' => 'nullable|in:category_icon,site_photo',
        ]);
        $isSitePhoto = ($data['purpose'] ?? null) === 'site_photo';
        $folder = $isSitePhoto ? 'site-photos' : 'category-icons';

        $file = $data['image'];
        $extension = strtolower((string) ($file->guessExtension() ?: 'png'));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'png';
        }

        $directory = public_path('branding/'.$folder);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = Str::uuid()->toString().'.'.$extension;
        $file->move($directory, $filename);

        $url = '/branding/'.$folder.'/'.$filename;
        Audit::record($r->user()->id, $isSitePhoto ? 'customization.site_photo_uploaded' : 'customization.category_icon_uploaded', 'frontend', ['path' => $url]);

        return response()->json([
            'ok' => true,
            'url' => $url,
        ]);
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
        $wasSendingBirthdaySms = (bool) DashboardManagement::get()['birthday_sms_enabled'];
        DashboardManagement::save($payload);
        Audit::record($r->user()->id, 'dashboard.updated', 'control_center');
        $isSendingBirthdaySms = (bool) DashboardManagement::get()['birthday_sms_enabled'];
        if ($wasSendingBirthdaySms !== $isSendingBirthdaySms) {
            Audit::record($r->user()->id, 'dashboard.birthday_sms_changed', 'control_center', ['from' => $wasSendingBirthdaySms, 'to' => $isSendingBirthdaySms]);
        }

        return back()->with('success', __('portal.flash_dashboard_saved'));
    }

    public function resetDashboardSettings(Request $r): RedirectResponse
    {
        abort_unless($r->user()->role === 'super_admin' || $r->user()->allows('accounts'), 403);
        DashboardManagement::reset();
        Audit::record($r->user()->id, 'dashboard.reset', 'control_center');

        return back()->with('success', __('portal.flash_dashboard_reset'));
    }

    public function storeSiteFeedback(Request $r): RedirectResponse
    {
        $user = $r->user();
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);

        $data = $r->validate([
            'location' => ['nullable', 'string', 'max:120', TestimonialLabels::NoHtmlRule],
            'rating' => 'required|integer|min:1|max:5',
            'message' => ['required', 'string', 'min:20', 'max:2000', TestimonialLabels::NoHtmlRule],
        ], [
            '*.not_regex' => __('site.feedback_no_html'),
        ]);

        $feedback = SiteFeedback::query()->create([
            'name' => $user->name,
            // Empty role: the site shows "Dealer" or "Sub-dealer" in the visitor's language.
            'role' => '',
            'location' => filled($data['location'] ?? null) ? $data['location'] : (string) ($user->district ?? ''),
            'rating' => (int) $data['rating'],
            'message' => $data['message'],
            'source' => $user->role === 'sub_dealer' ? 'sub_dealer' : 'dealer',
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        Audit::notifyAdminsAndStaff(
            ['content'],
            __('portal.notice_admin_feedback_title'),
            __('portal.notice_admin_feedback_body', [
                'name' => $feedback->name,
                'source' => $feedback->source,
            ]),
            $user->id
        );

        return back()->with('success', __('portal.flash_feedback_submitted'));
    }

    /**
     * Staff add a review a customer gave by phone, in person or on social media.
     * It is published at once, because the staff member is the one checking it.
     */
    public function storeStaffTestimonial(Request $r): RedirectResponse
    {
        abort_unless($r->user()->allows('content'), 403);

        $data = $r->validate([
            'name' => ['required', 'string', 'max:150', TestimonialLabels::NoHtmlRule],
            'role' => ['nullable', 'string', 'max:120', TestimonialLabels::NoHtmlRule],
            'location' => ['nullable', 'string', 'max:120', TestimonialLabels::NoHtmlRule],
            'rating' => 'required|integer|min:1|max:5',
            'message' => ['required', 'string', 'min:10', 'max:2000', TestimonialLabels::NoHtmlRule],
        ], [
            '*.not_regex' => __('site.feedback_no_html'),
        ]);

        $feedback = SiteFeedback::query()->create([
            'name' => trim($data['name']),
            'role' => trim((string) ($data['role'] ?? '')),
            'location' => trim((string) ($data['location'] ?? '')),
            'rating' => (int) $data['rating'],
            'message' => trim($data['message']),
            'source' => 'staff',
            'status' => 'approved',
            'moderated_by' => $r->user()->id,
            'moderated_at' => now(),
        ]);
        Cache::forget(SiteCustomization::TestimonialsCacheKey);

        Audit::record($r->user()->id, 'feedback.added_by_staff', 'frontend', [
            'feedback_id' => $feedback->id,
        ]);

        return back()->with('success', __('portal.flash_testimonial_added'));
    }

    public function moderateSiteFeedback(Request $r, int $id): RedirectResponse
    {
        abort_unless($r->user()->allows('content'), 403);

        $data = $r->validate([
            'status' => 'required|in:pending,approved,rejected',
        ]);

        $feedback = SiteFeedback::query()->findOrFail($id);
        $feedback->status = $data['status'];
        $feedback->moderated_by = $r->user()->id;
        $feedback->moderated_at = now();
        $feedback->save();
        Cache::forget(SiteCustomization::TestimonialsCacheKey);

        Audit::record($r->user()->id, 'feedback.moderated', 'frontend', [
            'feedback_id' => $feedback->id,
            'status' => $feedback->status,
        ]);

        return back()->with('success', __('portal.flash_feedback_moderated'));
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
            $locale = SmsText::Locale;
            $topicLabel = match ((string) $enquiry->topic) {
                'dealer_enquiry' => __('portal.enquiry_topic_dealer', [], $locale),
                'partnership_enquiry', 'Partnership' => __('portal.enquiry_topic_partnership', [], $locale),
                'product_enquiry' => __('portal.enquiry_topic_product', [], $locale),
                'support' => __('portal.support_topic_support', [], $locale),
                'complaint' => __('portal.support_topic_complaint', [], $locale),
                'product_problem' => __('public_trust.topic_product_problem', [], $locale),
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
                SmsText::make($template, [
                    'topic' => $topicLabel,
                    'status' => $statusLabel,
                ]),
            );
        }

        return back()->with('success', __('portal.flash_enquiry_updated'));
    }

    /**
     * The signed-in account's newest notices for the top-bar bell panel, loaded only when the panel opens.
     */
    public function latestNotices(Request $r): JsonResponse
    {
        $userId = $r->user()->id;
        $notices = DB::table('account_notices')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'title', 'body', 'read_at', 'created_at'])
            ->map(fn (object $notice): array => [
                'id' => (int) $notice->id,
                'title' => (string) $notice->title,
                'body' => Str::limit((string) $notice->body, 280),
                'read_at' => $notice->read_at !== null ? Carbon::parse($notice->read_at)->toIso8601String() : null,
                'created_at' => $notice->created_at !== null ? Carbon::parse($notice->created_at)->toIso8601String() : null,
            ])
            ->all();

        return response()->json(['data' => [
            'notices' => $notices,
            'unread' => $this->unreadNoticeCount($userId),
        ]]);
    }

    public function readNotice(Request $r, int $id): RedirectResponse|JsonResponse
    {
        abort_unless(DB::table('account_notices')->where('id', $id)->where('user_id', $r->user()->id)->update(['read_at' => now()]), 404);
        if ($r->expectsJson()) {
            return response()->json(['data' => ['unread' => $this->unreadNoticeCount($r->user()->id)]]);
        }

        return back();
    }

    public function readAllNotices(Request $r): RedirectResponse|JsonResponse
    {
        DB::table('account_notices')
            ->where('user_id', $r->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
        if ($r->expectsJson()) {
            return response()->json(['data' => ['unread' => 0]]);
        }

        return back()->with('success', __('portal.flash_notices_read'));
    }

    /**
     * How many of the account's notices are still unread, for the bell badge.
     */
    private function unreadNoticeCount(int $userId): int
    {
        return (int) DB::table('account_notices')->where('user_id', $userId)->whereNull('read_at')->count();
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
            'support' => SmsText::make('portal.support_topic_support'),
            'complaint' => SmsText::make('portal.support_topic_complaint'),
            'order_dispute' => SmsText::make('portal.support_topic_order'),
            'kyc_correction' => SmsText::make('portal.support_topic_kyc'),
            'payout_question' => SmsText::make('portal.support_topic_payout'),
            default => $topicKey,
        };
        app(PartnerEventSms::class)->send(
            $user,
            'support.requested',
            SmsText::make('sms.support_requested', ['topic' => $topicLabel]),
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

    /** Back-order date: when the items still waiting for stock are expected to go out. */
    public function supplyDate(Request $r, Order $order, OrderService $service): RedirectResponse
    {
        $this->permit($r, 'inventory');
        $data = $r->validate([
            'supply_eta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before:'.now()->addYear()->toDateString()],
            'supply_note' => ['nullable', 'string', 'max:300'],
        ]);
        $service->setSupplyDate($r->user(), $order, $data['supply_eta'] ?? null, $data['supply_note'] ?? null);

        return back()->with('success', __('portal.flash_supply_eta_saved'));
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
            SmsText::make('sms.account_invitation_sent', []),
        );

        return back()->with('success', __('portal.acct_invite_flash'));
    }

    /**
     * Whether a password can be reset or shown. This never returns the password itself:
     * the owner has to press "Show password", and that request is what gets audited.
     */
    public function partnerPasswordStatus(Request $r, User $user, PasswordAccess $access): JsonResponse
    {
        abort_unless($access->canReset($r->user(), $user), 403);

        return response()->json([
            'data' => [
                'managed_by' => $user->password_managed_by ?: User::PasswordManagedByAdmin,
                'can_reveal' => $access->canView($r->user(), $user) && $user->adminVisiblePassword() !== null,
                'owner_only' => ! $access->canView($r->user(), $user),
                'password' => null,
            ],
        ]);
    }

    /** Owners only: show the real password. Every view is audited with who, when and from where. */
    public function revealPartnerPassword(Request $r, User $user, PasswordAccess $access): JsonResponse
    {
        abort_unless($access->canView($r->user(), $user), 403, __('portal.acct_password_owner_only'));
        $visible = $user->adminVisiblePassword();
        abort_if($visible === null, 404, __('portal.acct_password_self_changed'));
        Audit::record($r->user()->id, 'account.password_revealed', (string) $user->id, [
            'role' => $user->role,
            'dealer_code' => $user->dealer_code,
            'ip' => $r->ip(),
        ]);

        return response()->json([
            'data' => [
                'managed_by' => $user->password_managed_by ?: User::PasswordManagedByAdmin,
                'can_reveal' => true,
                'owner_only' => false,
                'password' => $visible,
            ],
        ]);
    }

    public function resetPartnerPassword(Request $r, User $user, AccountCreatedSms $sms, PasswordAccess $access, PasswordResetOwnerNotice $ownerNotice): RedirectResponse|JsonResponse
    {
        abort_unless($access->canReset($r->user(), $user), 403);
        abort_unless($user->status === 'active', 422, __('portal.acct_password_inactive'));

        // Only owners may choose or see the new password. Other authorised staff can reset it, but
        // the new password goes only to the account holder by SMS, so staff cannot sign in as them.
        $ownerActor = $access->canView($r->user(), $user);
        // A reset nobody can read would lock the person out: without an owner the new password only goes by SMS.
        abort_unless($ownerActor || PhoneIdentity::normalize((string) $user->phone) !== null, 422, __('portal.acct_password_reset_needs_phone'));
        $custom = $ownerActor ? $r->input('password') : null;
        if (is_string($custom) && trim($custom) !== '') {
            $r->validate([
                'password' => ['required', 'string', 'min:8', 'max:64'],
            ]);
            $plainPassword = trim($custom);
        } else {
            $plainPassword = 'Md'.Str::upper(Str::random(2)).random_int(100000, 999999).'!';
        }

        DB::transaction(function () use ($r, $user, $plainPassword, $ownerActor): void {
            $user->storeAdminManagedPassword($plainPassword);
            $user->forceFill(['remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            Audit::record($r->user()->id, 'account.password_reset_by_admin', (string) $user->id, [
                'role' => $user->role,
                'dealer_code' => $user->dealer_code,
                'new_password_shown_to_actor' => $ownerActor,
                'chosen_by_actor' => $ownerActor && is_string($r->input('password')) && trim((string) $r->input('password')) !== '',
                'ip' => $r->ip(),
            ]);
        });

        if (filled($user->phone)) {
            $sms->sendPasswordReset($user->fresh(), $plainPassword);
        }
        // Staff who are not owners: the owners get an in-app notice (never with the password).
        $ownerNotice->notify($r->user(), $user);

        if ($r->expectsJson() || $r->wantsJson()) {
            return response()->json([
                'data' => [
                    'managed_by' => User::PasswordManagedByAdmin,
                    'can_reveal' => $ownerActor,
                    'owner_only' => ! $ownerActor,
                    'password' => $ownerActor ? $plainPassword : null,
                ],
                'message' => __('portal.acct_password_reset_flash'),
            ]);
        }

        return back()->with('success', $ownerActor
            ? __('portal.acct_password_reset_flash_with_password', ['password' => $plainPassword])
            : __('portal.acct_password_reset_flash'));
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

        return $documents->download($r->user(), $user, $document);
    }

    public function updateCommissionRules(Request $r, CommissionPolicy $policy): RedirectResponse
    {
        $this->permit($r, 'finance');
        $data = $r->validate(['rules' => 'required|array', 'reason' => 'nullable|string|max:500']);
        $result = $policy->update($r->user(), $data['rules'], $data['reason'] ?? null);

        return back()->with('success', $result['status'] === 'pending'
            ? __('portal.flash_commission_change_pending')
            : __('portal.flash_commission_policy_updated'));
    }

    public function settleSalaryPool(Request $r, CommissionService $commissionService): RedirectResponse
    {
        $this->permit($r, 'finance');
        $d = $r->validate(['period_month' => 'required|date_format:Y-m', 'request_key' => 'required|uuid']);
        $result = $commissionService->settleMonthlySalaryPool($d['period_month'], $r->user()->id, $d['request_key']);

        if ($result['status'] === 'empty_pool') {
            return back()->with('success', __('portal.flash_salary_pool_empty', ['period' => $d['period_month']]));
        }
        $rolled = $result['total_rolled_to_reserve'] ?? '0.00';
        $toProfit = $result['total_to_company_profit'] ?? '0.00';
        $extra = (Money::cents((string) $rolled) > 0 ? __('portal.flash_salary_pool_reserve_extra', ['amount' => $rolled]) : '')
            .(Money::cents((string) $toProfit) > 0 ? __('portal.flash_salary_profit_extra', ['amount' => $toProfit]) : '');
        if ($result['status'] === 'rolled_over') {
            return back()->with('success', __('portal.flash_salary_pool_none_qualified', ['period' => $d['period_month']]).$extra);
        }

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
        $toProfit = $result['total_to_company_profit'] ?? '0.00';
        $extra = (Money::cents((string) $rolled) > 0 ? __('portal.flash_royalty_pool_reserve_extra', ['amount' => $rolled]) : '')
            .(Money::cents((string) $toProfit) > 0 ? __('portal.flash_royalty_profit_extra', ['amount' => $toProfit]) : '');

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
            // An area seat is always a place code; names are filled in from it, never typed.
            'place_code_id' => 'required|integer|exists:place_codes,id',
            'zone_name' => 'nullable|string|max:128',
            'monthly_quota' => 'required|numeric|min:0|max:99999999999|decimal:0,2',
        ], [
            'place_code_id.required' => __('portal.place_code_required'),
        ]);

        $place = PlaceCode::query()
            ->whereKey($d['place_code_id'])
            ->where('level', $d['level'])
            ->where('is_active', true)
            ->first();
        if (! $place) {
            throw ValidationException::withMessages(['place_code_id' => __('portal.cmd_val_place_level_mismatch')]);
        }
        if (! $place->isReadyForAssignment()) {
            throw ValidationException::withMessages(['place_code_id' => __('portal.err_pc_placeholder_assignment')]);
        }
        $path = app(PlaceTree::class)->namesDownTo($place);
        foreach (['division', 'district', 'upazila', 'union'] as $field) {
            $value = $path[$field] ?? '';
            if (trim($value) === '' || PlaceCode::isPlaceholderLabel($value)) {
                throw ValidationException::withMessages(['place_code_id' => __('portal.cmd_val_place_hierarchy')]);
            }
            $d[$field] = $value;
            if ($field === $d['level']) {
                break;
            }
        }
        $d['zone_name'] = trim((string) ($d['zone_name'] ?? '')) !== ''
            ? trim((string) $d['zone_name'])
            : $place->name.' ('.$place->code.')';

        DB::transaction(function () use ($d, $place): void {
            CommissionWallet::lock();
            $user = User::whereKey($d['user_id'])->lockForUpdate()->firstOrFail();
            if ($user->role !== 'dealer' || $user->status !== 'active') {
                throw ValidationException::withMessages(['user_id' => __('portal.cmd_val_active_dealer')]);
            }
            if (AreaDealerAssignment::where('level', $d['level'])->where('is_active', true)->where('place_code_id', $place->id)->exists()) {
                throw ValidationException::withMessages(['place_code_id' => __('portal.cmd_val_territory_taken')]);
            }
            AreaDealerAssignment::create([
                ...collect($d)->only(['user_id', 'level', 'zone_name', 'division', 'district', 'upazila', 'union', 'monthly_quota'])->all(),
                'place_code_id' => $place->id,
                'territory_key' => hash('sha256', $d['level'].'|code:'.$place->code),
                'is_active' => true,
            ]);
        }, 3);
        Audit::record($r->user()->id, 'area_dealer.assigned', (string) $d['user_id'], [
            'level' => $d['level'],
            'zone' => $d['zone_name'],
            'place_code' => $place->code,
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
                SmsText::make('sms.area_dealer_assigned', [
                    'zone' => $d['zone_name'],
                    'quota' => $d['monthly_quota'],
                ]),
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
                SmsText::make('sms.area_dealer_removed', [
                    'zone' => $assignment->zone_name,
                ]),
            );
        }

        return back()->with('success', __('portal.flash_area_dealer_removed'));
    }

    public function supportTicket(Request $r, SupportTickets $tickets): RedirectResponse
    {
        $r->validate([
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);
        $tickets->create($r->user(), $r->only(['category', 'subject', 'body']), $r->file('attachment'));

        return back()->with('success', __('portal.flash_support_ticket_sent'));
    }

    public function supportTicketReply(Request $r, string $id, SupportTickets $tickets): RedirectResponse
    {
        $data = $r->validate([
            'body' => 'required|string|max:5000',
            'status' => 'nullable|in:pending,in_progress,resolved',
        ]);
        $tickets->reply($r->user(), $id, $data['body'], $data['status'] ?? null);

        return back()->with('success', __('portal.flash_support_reply_saved'));
    }

    public function completeTour(Request $r, string $page, TourGuide $tours): RedirectResponse|JsonResponse
    {
        $tours->complete($r->user(), $page);
        if ($r->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function restartTour(Request $r, TourGuide $tours): RedirectResponse
    {
        $page = $r->string('page')->toString() ?: null;
        $tours->restart($r->user(), $page ?: null);

        return back()->with('success', __('portal.flash_tour_restarted'));
    }

    public function switchWorkspaceMode(Request $r, PartnerStaffLink $link): RedirectResponse
    {
        $mode = $r->validate(['mode' => 'required|in:partner,staff'])['mode'];
        $link->switchMode($r->user(), $mode);

        return redirect('/')->with('success', __('portal.flash_workspace_switched', ['mode' => $mode]));
    }

    public function linkStaffRole(Request $r, User $user, PartnerStaffLink $link): RedirectResponse
    {
        $this->permit($r, 'accounts');
        abort_unless($r->user()->role === 'super_admin' || $r->user()->allows('accounts'), 403);
        $data = $r->validate([
            'linked_staff_role' => 'required|string|max:80',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|max:40',
        ]);
        $link->link($r->user(), $user, $data['linked_staff_role'], $data['permissions'] ?? []);

        return back()->with('success', __('portal.flash_staff_linked'));
    }

    public function unlinkStaffRole(Request $r, User $user, PartnerStaffLink $link): RedirectResponse
    {
        $this->permit($r, 'accounts');
        $link->unlink($r->user(), $user);

        return back()->with('success', __('portal.flash_staff_unlinked'));
    }
}
