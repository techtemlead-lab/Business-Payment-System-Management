<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Geography\PlaceGeographyResolver;
use App\Domain\Notifications\AccountCreatedSms;
use App\Models\AreaDealerAssignment;
use App\Models\DealerRank;
use App\Models\PlaceCode;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PartnerAccountProvisioning
{
    public function __construct(
        private PlaceGeographyResolver $geography,
        private AccountCreatedSms $sms,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{user: User, plain_password: string, place_label: ?string, commission_consumers: array<string, mixed>}
     */
    public function create(User $actor, array $input): array
    {
        $role = (string) ($input['role'] ?? '');
        if (! in_array($role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['role' => 'Choose dealer or sub-dealer.']);
        }

        $phone = $this->normalizeBdMobile((string) ($input['phone'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $referCode = strtoupper(trim((string) ($input['refer_code'] ?? '')));
        $referenceCode = strtoupper(trim((string) ($input['reference_code'] ?? '')));
        $identityType = (string) ($input['identity_type'] ?? 'birth_certificate');
        if (! in_array($identityType, ['birth_certificate', 'nid'], true)) {
            throw ValidationException::withMessages(['identity_type' => 'Choose birth certificate or NID.']);
        }

        if ($referCode === '') {
            throw ValidationException::withMessages(['refer_code' => 'Enter this partner’s unique refer code.']);
        }
        if ($referenceCode !== '' && $referenceCode === $referCode) {
            throw ValidationException::withMessages([
                'refer_code' => 'Your refer code cannot be the same as the reference code.',
                'reference_code' => 'Reference code and your refer code must be different.',
            ]);
        }
        if (User::query()->whereRaw('UPPER(refer_code) = ?', [$referCode])->exists()) {
            throw ValidationException::withMessages(['refer_code' => 'This refer code is already in use.']);
        }
        if (User::query()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'This mobile number already has an account.']);
        }
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already has an account.']);
        }

        $sponsor = null;
        if ($referenceCode !== '') {
            $sponsor = User::query()
                ->whereRaw('UPPER(refer_code) = ?', [$referenceCode])
                ->where('role', 'dealer')
                ->where('status', 'active')
                ->first();
            if (! $sponsor) {
                throw ValidationException::withMessages(['reference_code' => 'No active dealer found with that reference code.']);
            }
        }

        $placeCodeValue = trim((string) ($input['place_code'] ?? ''));
        if ($placeCodeValue === '') {
            throw ValidationException::withMessages(['place_code' => $role === 'sub_dealer'
                ? 'Enter a union / city corporation code.'
                : 'Enter the area place code.']);
        }

        $place = PlaceCode::query()->where('code', $placeCodeValue)->where('is_active', true)->first();
        if (! $place) {
            throw ValidationException::withMessages(['place_code' => 'Unknown or inactive place code.']);
        }
        if ($role === 'sub_dealer' && $place->level !== PlaceCode::LevelUnion) {
            throw ValidationException::withMessages(['place_code' => 'Sub-dealers must be linked to a union / city corporation code.']);
        }
        if ($role === 'dealer' && $place->level === PlaceCode::LevelManagement) {
            throw ValidationException::withMessages(['place_code' => 'Dealers cannot be assigned the management code.']);
        }

        if ($role === 'dealer') {
            $taken = AreaDealerAssignment::query()
                ->where('place_code_id', $place->id)
                ->where('is_active', true)
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['place_code' => 'This area code already has an active dealer.']);
            }
            $dealerCode = trim((string) ($input['dealer_code'] ?? ''));
            if ($dealerCode === '') {
                $dealerCode = $place->code;
            }
            if (User::query()->where('dealer_code', $dealerCode)->exists()) {
                throw ValidationException::withMessages(['dealer_code' => 'This dealer code is already in use.']);
            }
        } else {
            $dealerCode = $this->nextSubDealerCode($place->code);
        }

        $path = $this->geography->expandFromAnchor($place);
        $plainPassword = $this->temporaryPassword();
        $profile = [
            'name_bn' => trim((string) ($input['name_bn'] ?? '')),
            'father_name' => trim((string) ($input['father_name'] ?? '')),
            'mother_name' => trim((string) ($input['mother_name'] ?? '')),
            'occupation' => trim((string) ($input['occupation'] ?? '')),
            'village' => trim((string) ($input['village'] ?? '')),
            'post_office' => trim((string) ($input['post_office'] ?? '')),
            'upazila' => $path['upazila'] ?? trim((string) ($input['upazila'] ?? '')),
            'union' => $path['union'] ?? trim((string) ($input['union'] ?? '')),
            'pharmacy' => trim((string) ($input['pharmacy'] ?? '')),
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
            throw ValidationException::withMessages(['nid' => 'Enter the NID number.']);
        }
        if ($identityType === 'birth_certificate' && trim((string) ($input['birth_certificate_number'] ?? '')) === '') {
            throw ValidationException::withMessages(['birth_certificate_number' => 'Enter the birth certificate number.']);
        }
        if ($profile['date_of_birth'] === '') {
            throw ValidationException::withMessages(['date_of_birth' => 'Enter the date of birth.']);
        }
        foreach (['name' => 'English name', 'name_bn' => 'Bangla name', 'father_name' => 'Father’s name', 'mother_name' => 'Mother’s name'] as $field => $label) {
            $value = $field === 'name' ? trim((string) ($input['name'] ?? '')) : $profile[$field];
            if ($value === '') {
                throw ValidationException::withMessages([$field === 'name' ? 'name' : $field => $label.' is required.']);
            }
        }

        $user = DB::transaction(function () use ($actor, $input, $role, $phone, $email, $referCode, $sponsor, $place, $path, $dealerCode, $profile, $plainPassword): User {
            CommissionWallet::lock();
            $user = User::create([
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
                'directory_visible' => $role === 'dealer' && ! empty($input['directory_visible']),
                'status' => in_array(($status = (string) ($input['status'] ?? 'active')), ['active', 'suspended'], true) ? $status : 'active',
                'password' => Hash::make($plainPassword),
                'profile' => $profile,
            ]);
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
                'reason' => $input['reason'] ?? 'Partner onboarding authorized by company staff.',
            ]);

            return $user;
        }, 3);

        $this->sms->send($user, $plainPassword);

        return [
            'user' => $user,
            'plain_password' => $plainPassword,
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
     * @return array{label:string,path:array<string,mixed>,commission_places:list<array{level:string,code:?string,name:?string}>,available:bool}
     */
    public function resolvePlacePreview(string $code, string $role = 'dealer'): array
    {
        $place = PlaceCode::query()->where('code', trim($code))->where('is_active', true)->first();
        if (! $place) {
            throw ValidationException::withMessages(['place_code' => 'Unknown or inactive place code.']);
        }
        if ($role === 'sub_dealer' && $place->level !== PlaceCode::LevelUnion) {
            throw ValidationException::withMessages(['place_code' => 'Sub-dealers must use a union / city corporation code.']);
        }
        $path = $this->geography->expandFromAnchor($place);
        $available = $role === 'sub_dealer' || ! AreaDealerAssignment::query()
            ->where('place_code_id', $place->id)
            ->where('is_active', true)
            ->exists();
        $commissionPlaces = [];
        foreach (['division', 'district', 'upazila', 'union'] as $level) {
            $commissionPlaces[] = [
                'level' => $level,
                'code' => $path['codes'][$level] ?? null,
                'name' => $path[$level] ?? null,
            ];
        }

        return [
            'label' => $this->placeLabel($place),
            'path' => $path,
            'commission_places' => $commissionPlaces,
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
        $prefix = $unionCode.'-';
        $latest = User::query()
            ->where('role', 'sub_dealer')
            ->where('dealer_code', 'like', $prefix.'%')
            ->orderByDesc('dealer_code')
            ->value('dealer_code');
        $next = 1;
        if (is_string($latest) && preg_match('/^'.preg_quote($prefix, '/').'(\d{6})$/', $latest, $matches)) {
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
            throw ValidationException::withMessages(['phone' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).']);
        }

        return $digits;
    }

    private function temporaryPassword(): string
    {
        return 'Md'.Str::upper(Str::random(2)).random_int(100000, 999999).'!';
    }
}
