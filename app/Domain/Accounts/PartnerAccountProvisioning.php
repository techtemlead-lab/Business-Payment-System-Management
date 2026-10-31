<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Geography\PlaceGeographyResolver;
use App\Domain\Notifications\AccountCreatedSms;
use App\Domain\Notifications\PartnerEventSms;
use App\Models\AreaDealerAssignment;
use App\Models\DealerRank;
use App\Models\PlaceCode;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PartnerAccountProvisioning
{
    public function __construct(
        private PlaceGeographyResolver $geography,
        private AccountCreatedSms $sms,
        private CommissionPolicy $policies,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{user: User, place_label: ?string, commission_consumers: array<string, mixed>}
     */
    public function create(User $actor, array $input): array
    {
        $role = (string) ($input['role'] ?? '');
        if (! in_array($role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['role' => __('portal.acct_val_role')]);
        }

        $phone = $this->normalizeBdMobile((string) ($input['phone'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $referenceCode = strtoupper(trim((string) ($input['reference_code'] ?? '')));
        $identityType = (string) ($input['identity_type'] ?? 'birth_certificate');
        if (! in_array($identityType, ['birth_certificate', 'nid'], true)) {
            throw ValidationException::withMessages(['identity_type' => __('portal.acct_val_identity_type')]);
        }

        if (User::query()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => __('portal.acct_val_phone_taken')]);
        }
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('portal.acct_val_email_taken')]);
        }

        $sponsor = null;
        if ($referenceCode !== '') {
            $sponsor = User::query()
                ->where(function ($query) use ($referenceCode): void {
                    $query->whereRaw('UPPER(refer_code) = ?', [$referenceCode])
                        ->orWhereRaw('UPPER(dealer_code) = ?', [$referenceCode]);
                })
                ->where('role', 'dealer')
                ->where('status', 'active')
                ->first();
            if (! $sponsor) {
                throw ValidationException::withMessages(['reference_code' => __('portal.acct_val_reference_missing')]);
            }
        }

        $placeCodeValue = trim((string) ($input['place_code'] ?? ''));
        if ($placeCodeValue === '') {
            throw ValidationException::withMessages(['place_code' => $role === 'sub_dealer'
                ? __('portal.acct_val_place_sub')
                : __('portal.acct_val_place_dealer')]);
        }

        $place = PlaceCode::findByCode($placeCodeValue);
        if (! $place) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_unknown')]);
        }
        if (! $place->isReadyForAssignment()) {
            throw ValidationException::withMessages(['place_code' => __('portal.err_pc_placeholder_assignment')]);
        }
        if ($role === 'sub_dealer' && $place->level !== PlaceCode::LevelUnion) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_sub_level')]);
        }
        if ($role === 'dealer' && $place->level === PlaceCode::LevelManagement) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_management')]);
        }

        if ($role === 'dealer') {
            $taken = AreaDealerAssignment::query()
                ->where('place_code_id', $place->id)
                ->where('is_active', true)
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_taken')]);
            }
            $dealerCode = PlaceCode::brandSerial(trim((string) ($input['dealer_code'] ?? ''))) ?: $place->code;
            if (User::query()->where('dealer_code', $dealerCode)->exists()) {
                throw ValidationException::withMessages(['dealer_code' => __('portal.acct_val_dealer_code_taken')]);
            }
        } else {
            $dealerCode = $this->nextSubDealerCode($place->code);
        }

        // By default refer_code matches dealer/sub-dealer code; admin may override explicitly.
        $referCode = strtoupper(trim((string) ($input['refer_code'] ?? '')));
        if ($referCode === '') {
            $referCode = strtoupper($dealerCode);
        }
        if ($referenceCode !== '' && $referenceCode === $referCode) {
            throw ValidationException::withMessages([
                'refer_code' => __('portal.acct_val_refer_same_as_reference'),
                'reference_code' => __('portal.acct_val_reference_same_as_refer'),
            ]);
        }
        if (User::query()->whereRaw('UPPER(refer_code) = ?', [$referCode])->exists()) {
            throw ValidationException::withMessages(['refer_code' => __('portal.acct_val_refer_taken')]);
        }
        if ($referCode !== strtoupper($dealerCode) && User::query()->whereRaw('UPPER(dealer_code) = ?', [$referCode])->exists()) {
            throw ValidationException::withMessages(['refer_code' => __('portal.acct_val_refer_taken')]);
        }

        $path = $this->geography->expandFromAnchor($place);
        $plainPassword = $this->temporaryPassword();
        $shopName = trim((string) ($input['shop_name'] ?? $input['pharmacy'] ?? ''));
        $profile = [
            'name_bn' => trim((string) ($input['name_bn'] ?? '')),
            'father_name' => trim((string) ($input['father_name'] ?? '')),
            'mother_name' => trim((string) ($input['mother_name'] ?? '')),
            'occupation' => trim((string) ($input['occupation'] ?? '')),
            'village' => trim((string) ($input['village'] ?? '')),
            'post_office' => trim((string) ($input['post_office'] ?? '')),
            'upazila' => $path['upazila'] ?? trim((string) ($input['upazila'] ?? '')),
            'union' => $path['union'] ?? trim((string) ($input['union'] ?? '')),
            'pharmacy' => $shopName,
            'shop_name' => $shopName,
            'area_code' => $role === 'sub_dealer' ? $dealerCode : $place->code,
            'place_code' => $place->code,
            'place_code_id' => $place->id,
            'identity_type' => $identityType,
            'birth_certificate_number' => $identityType === 'birth_certificate' ? trim((string) ($input['birth_certificate_number'] ?? '')) : null,
            'date_of_birth' => trim((string) ($input['date_of_birth'] ?? '')),
            'extra_contact' => trim((string) ($input['extra_contact'] ?? '')),
            'union_place_code' => $role === 'sub_dealer' ? $place->code : null,
        ];

        if ($identityType === 'nid' && trim((string) ($input['nid'] ?? '')) === '') {
            throw ValidationException::withMessages(['nid' => __('portal.acct_val_nid_required')]);
        }
        if ($identityType === 'birth_certificate' && trim((string) ($input['birth_certificate_number'] ?? '')) === '') {
            throw ValidationException::withMessages(['birth_certificate_number' => __('portal.acct_val_birth_cert_required')]);
        }
        if ($profile['date_of_birth'] === '') {
            throw ValidationException::withMessages(['date_of_birth' => __('portal.acct_val_dob_required')]);
        }
        foreach ([
            'name' => 'portal.acct_val_name_en_required',
            'name_bn' => 'portal.acct_val_name_bn_required',
            'father_name' => 'portal.acct_val_father_required',
            'mother_name' => 'portal.acct_val_mother_required',
        ] as $field => $messageKey) {
            $value = $field === 'name' ? trim((string) ($input['name'] ?? '')) : $profile[$field];
            if ($value === '') {
                throw ValidationException::withMessages([$field === 'name' ? 'name' : $field => __($messageKey)]);
            }
        }

        $user = DB::transaction(function () use ($actor, $input, $role, $phone, $email, $referCode, $sponsor, $place, $path, $dealerCode, $profile, $plainPassword): User {
            CommissionWallet::lock();
            $user = (new User)->forceFill([
                'role' => $role,
                'dealer_code' => $dealerCode,
                'refer_code' => $referCode,
                'name' => trim((string) $input['name']),
                'email' => $email,
                'phone' => $phone,
                'division' => $path['division'] ?? ($input['division'] ?? null),
                'district' => $path['district'] ?? ($input['district'] ?? null),
                'sponsor_id' => $sponsor?->id,
                'nid' => trim((string) ($input['nid'] ?? '')) ?: null,
                'latitude' => isset($input['latitude']) && $input['latitude'] !== '' ? (float) $input['latitude'] : null,
                'longitude' => isset($input['longitude']) && $input['longitude'] !== '' ? (float) $input['longitude'] : null,
                'directory_visible' => $role === 'dealer' && ! empty($input['directory_visible']),
                'status' => in_array(($status = (string) ($input['status'] ?? 'active')), ['active', 'suspended'], true) ? $status : 'active',
                'password' => $plainPassword,
                'password_managed_by' => User::PasswordManagedByAdmin,
                'admin_password_cipher' => $plainPassword,
                'profile' => $profile,
            ]);
            $user->save();
            DB::table('wallets')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            ReferralTreePath::attachUser($user->id, $user->sponsor_id);
            DealerRank::firstOrCreate(['user_id' => $user->id], ['current_rank' => 'dealer']);

            if ($role === 'dealer') {
                AreaDealerAssignment::create([
                    'user_id' => $user->id,
                    'level' => $place->level,
                    'place_code_id' => $place->id,
                    'zone_name' => $place->name.' ('.$place->code.')',
                    'division' => $path['division'],
                    'district' => $path['district'],
                    'upazila' => $path['upazila'],
                    'union' => $path['union'],
                    'monthly_quota' => match ($place->level) {
                        'division' => '50000.00',
                        'district' => '40000.00',
                        'upazila' => '20000.00',
                        default => '10000.00',
                    },
                    'territory_key' => hash('sha256', $place->level.'|code:'.$place->code),
                    'is_active' => true,
                ]);
            }

            Audit::record($actor->id, 'account.created', (string) $user->id, [
                'role' => $role,
                'dealer_code' => $dealerCode,
                'place_code' => $place->code,
                'refer_code' => $referCode,
                'reference_code' => $sponsor?->refer_code,
                'reason' => $input['reason'] ?? __('portal.acct_default_onboarding_reason'),
            ]);

            return $user;
        }, 3);

        // One-time credentials travel only through the SMS channel — never JSON/Inertia props.
        $this->sms->send($user, $plainPassword);

        return [
            'user' => $user,
            'place_label' => $this->placeLabel($place),
            'commission_consumers' => $this->commissionConsumers($sponsor),
        ];
    }

    public function placeLabel(PlaceCode $place): string
    {
        $level = $place->level === PlaceCode::LevelManagement
            ? 'Management'
            : ucfirst($place->level);

        return $place->code.'-'.$level.'-'.$place->name;
    }

    /**
     * Re-anchor a partner's commission geography to a catalog place code.
     * Dealers move their active AreaDealerAssignment seat; sub-dealers keep
     * their serial dealer_code while updating union_place_code / place_code.
     */
    public function rebindGeography(User $actor, User $partner, string $placeCodeValue, string $reason): void
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_role')]);
        }

        $placeCodeValue = trim($placeCodeValue);
        if ($placeCodeValue === '') {
            throw ValidationException::withMessages(['place_code' => $partner->role === 'sub_dealer'
                ? __('portal.acct_val_place_sub')
                : __('portal.acct_val_place_dealer')]);
        }

        $place = PlaceCode::findByCode($placeCodeValue);
        if (! $place) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_unknown')]);
        }
        if (! $place->isReadyForAssignment()) {
            throw ValidationException::withMessages(['place_code' => __('portal.err_pc_placeholder_assignment')]);
        }
        if ($partner->role === 'sub_dealer' && $place->level !== PlaceCode::LevelUnion) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_sub_level')]);
        }
        if ($partner->role === 'dealer' && $place->level === PlaceCode::LevelManagement) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_management')]);
        }

        $currentCode = (string) (($partner->profile['place_code'] ?? '') ?: '');
        if ($currentCode !== '' && strcasecmp($currentCode, $place->code) === 0) {
            return;
        }

        if ($partner->role === 'dealer') {
            $taken = AreaDealerAssignment::query()
                ->where('place_code_id', $place->id)
                ->where('is_active', true)
                ->where('user_id', '!=', $partner->id)
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_taken')]);
            }
        }

        $path = $this->geography->expandFromAnchor($place);

        DB::transaction(function () use ($actor, $partner, $place, $path, $reason, $currentCode): void {
            CommissionWallet::lock();
            $locked = User::whereKey($partner->id)->lockForUpdate()->firstOrFail();
            $profile = is_array($locked->profile) ? $locked->profile : [];
            $profile['place_code'] = $place->code;
            $profile['place_code_id'] = $place->id;
            $profile['upazila'] = $path['upazila'] ?? ($profile['upazila'] ?? null);
            $profile['union'] = $path['union'] ?? ($profile['union'] ?? null);
            if ($locked->role === 'dealer') {
                $profile['area_code'] = $place->code;
                $profile['union_place_code'] = null;
            } else {
                $profile['union_place_code'] = $place->code;
            }

            $locked->forceFill([
                'division' => $path['division'] ?? $locked->division,
                'district' => $path['district'] ?? $locked->district,
                'profile' => $profile,
            ])->save();

            if ($locked->role === 'dealer') {
                AreaDealerAssignment::query()
                    ->where('user_id', $locked->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);
                AreaDealerAssignment::create([
                    'user_id' => $locked->id,
                    'level' => $place->level,
                    'place_code_id' => $place->id,
                    'zone_name' => $place->name.' ('.$place->code.')',
                    'division' => $path['division'],
                    'district' => $path['district'],
                    'upazila' => $path['upazila'],
                    'union' => $path['union'],
                    'monthly_quota' => match ($place->level) {
                        'division' => '50000.00',
                        'district' => '40000.00',
                        'upazila' => '20000.00',
                        default => '10000.00',
                    },
                    'territory_key' => hash('sha256', $place->level.'|code:'.$place->code),
                    'is_active' => true,
                ]);
            }

            Audit::record($actor->id, 'account.geography_updated', (string) $locked->id, [
                'from_place_code' => $currentCode !== '' ? $currentCode : null,
                'to_place_code' => $place->code,
                'role' => $locked->role,
                'reason' => $reason,
            ]);
            $locale = $locked->preferredLocale();
            $label = $place->name.' ('.$place->code.')';
            Audit::notice(
                $locked->id,
                __('portal.notice_geography_updated_title', [], $locale),
                __('portal.notice_geography_updated_body', [
                    'place' => $label,
                    'reason' => $reason,
                ], $locale),
            );
            DB::afterCommit(function () use ($locked, $locale, $label, $reason): void {
                app(PartnerEventSms::class)->send(
                    $locked,
                    'account.geography_updated',
                    __('sms.geography_updated', [
                        'place' => $label,
                        'reason' => $reason,
                    ], $locale),
                );
            });
        }, 3);
    }

    /**
     * @return array{
     *   label:string,
     *   path:array<string,mixed>,
     *   commission_places:list<array{level:string,code:?string,name:?string,rate:?string,pool:?string}>,
     *   area_pool_rate:string,
     *   management_rate:string,
     *   available:bool,
     *   next_sub_dealer_code:?string
     * }
     */
    public function resolvePlacePreview(string $code, string $role = 'dealer'): array
    {
        $place = PlaceCode::findByCode($code);
        if (! $place) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_unknown')]);
        }
        if (! $place->isReadyForAssignment()) {
            throw ValidationException::withMessages(['place_code' => __('portal.err_pc_placeholder_assignment')]);
        }
        if ($role === 'sub_dealer' && $place->level !== PlaceCode::LevelUnion) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_sub_preview')]);
        }
        $path = $this->geography->expandFromAnchor($place);
        $available = $role === 'sub_dealer' || ! AreaDealerAssignment::query()
            ->where('place_code_id', $place->id)
            ->where('is_active', true)
            ->exists();
        $areaRules = $this->policies->snapshot(false)['rules']['area_dealer_pool'] ?? [];
        $areaParams = is_array($areaRules['parameters'] ?? null) ? $areaRules['parameters'] : [];
        $areaPoolRate = (string) ($areaRules['percentage'] ?? '10.00');
        $managementRate = (string) ($areaParams['management_share_pct'] ?? '2.00');
        $commissionPlaces = [];
        foreach (['management', 'division', 'district', 'upazila', 'union'] as $level) {
            $rateKey = $level.'_share_pct';
            $commissionPlaces[] = [
                'level' => $level,
                'code' => $path['codes'][$level] ?? null,
                'name' => $level === 'management'
                    ? ($path['management'] ?? __('portal.acct_mgmt_company'))
                    : ($path[$level] ?? null),
                'rate' => isset($areaParams[$rateKey]) ? (string) $areaParams[$rateKey] : null,
                'pool' => $areaPoolRate,
            ];
        }

        return [
            'label' => $this->placeLabel($place),
            'path' => $path,
            'commission_places' => $commissionPlaces,
            'area_pool_rate' => $areaPoolRate,
            'management_rate' => $managementRate,
            'available' => $available,
            'next_sub_dealer_code' => $role === 'sub_dealer' ? $this->nextSubDealerCode($place->code) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function commissionConsumers(?User $sponsor): array
    {
        if (! $sponsor) {
            return [
                'direct_referral' => null,
                'd2d_gen2' => null,
                'd2d_gen3' => null,
                'unassigned' => ['direct_referral' => '10%', 'd2d_gen2' => '2%', 'd2d_gen3' => '1%'],
            ];
        }
        $gen2 = $sponsor->sponsor_id ? User::query()->find($sponsor->sponsor_id) : null;
        $gen3 = $gen2?->sponsor_id ? User::query()->find($gen2->sponsor_id) : null;
        $unassigned = [];
        if (! $gen2) {
            $unassigned['d2d_gen2'] = '2%';
        }
        if (! $gen3) {
            $unassigned['d2d_gen3'] = '1%';
        }

        return [
            'direct_referral' => ['id' => $sponsor->id, 'name' => $sponsor->name, 'refer_code' => $sponsor->refer_code, 'rate' => '10%'],
            'd2d_gen2' => $gen2 ? ['id' => $gen2->id, 'name' => $gen2->name, 'refer_code' => $gen2->refer_code, 'rate' => '2%'] : null,
            'd2d_gen3' => $gen3 ? ['id' => $gen3->id, 'name' => $gen3->name, 'refer_code' => $gen3->refer_code, 'rate' => '1%'] : null,
            'unassigned' => $unassigned,
        ];
    }

    public function nextSubDealerCode(string $unionCode): string
    {
        $brandedUnion = PlaceCode::normalizeCode($unionCode) ?? PlaceCode::brandSerial($unionCode) ?? $unionCode;
        $prefix = rtrim((string) $brandedUnion, '-').'-';
        $legacyPrefix = (PlaceCode::normalizeCode($unionCode) ? substr((string) PlaceCode::normalizeCode($unionCode), strlen(PlaceCode::BrandPrefix) + 1) : $unionCode).'-';
        $latest = User::query()
            ->where('role', 'sub_dealer')
            ->where(function ($q) use ($prefix, $legacyPrefix): void {
                $q->where('dealer_code', 'like', $prefix.'%')
                    ->orWhere('dealer_code', 'like', $legacyPrefix.'%');
            })
            ->orderByDesc('dealer_code')
            ->value('dealer_code');
        $next = 1;
        if (is_string($latest) && preg_match('/(?:^'.preg_quote($prefix, '/').'|^'.preg_quote($legacyPrefix, '/').')(\d{6})$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function normalizeBdMobile(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            $digits = substr($digits, 2);
        }
        if (! preg_match('/^01[3-9]\d{8}$/', $digits)) {
            throw ValidationException::withMessages(['phone' => __('portal.acct_phone_bd_invalid')]);
        }

        return $digits;
    }

    private function temporaryPassword(): string
    {
        return 'Md'.Str::upper(Str::random(2)).random_int(100000, 999999).'!';
    }
}
