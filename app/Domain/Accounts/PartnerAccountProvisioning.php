<?php

namespace App\Domain\Accounts;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Geography\ManagementReferral;
use App\Domain\Geography\PlaceCodeCatalog;
use App\Domain\Geography\PlaceGeographyResolver;
use App\Domain\Notifications\AccountCreatedSms;
use App\Domain\Notifications\PartnerEventEmail;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Models\AreaDealerAssignment;
use App\Models\DealerRank;
use App\Models\PlaceCode;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PartnerAccountProvisioning
{
    /** Marks a closed account's code after someone else was given the real one. */
    public const RetiredCodeMark = '-CLOSED-';

    /** The places closing took from a dealer, kept on the profile for when it reopens. */
    public const FreedSeatsKey = 'seats_freed_on_close';

    public function __construct(
        private PlaceGeographyResolver $geography,
        private AccountCreatedSms $sms,
        private CommissionPolicy $policies,
        private ManagementReferral $managementReferral,
        private AccountPhoneRules $phones,
        private PartnerFormerCodes $formerCodes,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{user: User, place_label: ?string, commission_consumers: array<string, mixed>, credentials_sent: bool}
     */
    public function create(User $actor, array $input): array
    {
        $role = (string) ($input['role'] ?? '');
        if (! in_array($role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['role' => __('portal.acct_val_role')]);
        }

        $phoneCheck = $this->phones->check($actor, $input['phone'] ?? '', null, $input['phone_share_reason'] ?? null);
        $phone = $phoneCheck['phone'];
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $referenceCode = strtoupper(trim((string) ($input['reference_code'] ?? '')));
        $identityType = (string) ($input['identity_type'] ?? 'birth_certificate');
        if (! in_array($identityType, ['birth_certificate', 'nid'], true)) {
            throw ValidationException::withMessages(['identity_type' => __('portal.acct_val_identity_type')]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('portal.acct_val_email_taken')]);
        }

        $sponsor = null;
        $managementReferral = false;
        $managementSettings = $this->managementReferral->settings();
        if ($referenceCode === '') {
            if ($managementSettings['enabled'] && $managementSettings['auto_blank_reference']) {
                if ($managementSettings['place_code'] === null) {
                    throw ValidationException::withMessages(['reference_code' => __('portal.acct_val_management_refer_missing')]);
                }
                $managementReferral = true;
            }
        } elseif ($this->managementReferral->matchesReference($referenceCode)) {
            $managementReferral = true;
        } else {
            $sponsor = $this->findSponsor($referenceCode);
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
            if (User::query()->where('dealer_code', $dealerCode)->whereNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['dealer_code' => __('portal.acct_val_dealer_code_taken')]);
            }
        } else {
            $dealerCode = $this->nextSubDealerCode($place->code);
        }

        // By default refer_code matches dealer/sub-dealer code; admin may override explicitly.
        $referCode = strtoupper(trim((string) ($input['refer_code'] ?? '')));
        if ($referCode === '' || PlaceCode::brandSerial($referCode) === strtoupper($dealerCode)) {
            $referCode = strtoupper($dealerCode);
        }
        if ($referenceCode !== '' && $this->isSameCode($referenceCode, $referCode)) {
            throw ValidationException::withMessages([
                'refer_code' => __('portal.acct_val_refer_same_as_reference'),
                'reference_code' => __('portal.acct_val_reference_same_as_refer'),
            ]);
        }
        if ($this->managementReferral->matchesReference($referCode)) {
            throw ValidationException::withMessages(['refer_code' => __('portal.acct_val_refer_is_management')]);
        }
        if ($this->referCodeInUse($referCode, openAccountsOnly: true)) {
            throw ValidationException::withMessages(['refer_code' => __('portal.acct_val_refer_taken')]);
        }
        if ($referCode !== strtoupper($dealerCode) && User::query()->whereNull('closed_at')->whereRaw('UPPER(dealer_code) = ?', [$referCode])->exists()) {
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
        if ($managementReferral) {
            $profile['referred_by'] = ManagementReferral::ProfileFlag;
            $profile['management_refer_code'] = $managementSettings['place_code'];
            $profile['management_place_code_id'] = $managementSettings['place_code_id'];
        }

        if ($identityType === 'nid' && trim((string) ($input['nid'] ?? '')) === '') {
            throw ValidationException::withMessages(['nid' => __('portal.acct_val_nid_required')]);
        }
        if ($identityType === 'birth_certificate' && trim((string) ($input['birth_certificate_number'] ?? '')) === '') {
            throw ValidationException::withMessages(['birth_certificate_number' => __('portal.acct_val_birth_cert_required')]);
        }
        if ($profile['date_of_birth'] === '') {
            throw ValidationException::withMessages(['date_of_birth' => __('portal.acct_val_dob_required')]);
        }
        if (! PartnerAge::isAdult($profile['date_of_birth'])) {
            throw ValidationException::withMessages(['date_of_birth' => __('portal.acct_val_dob_under_age', ['years' => PartnerAge::MinimumYears])]);
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

        try {
            $user = DB::transaction(function () use ($actor, $input, $role, $phone, $email, $referCode, $sponsor, $place, $path, $dealerCode, $profile, $plainPassword, $managementReferral, $managementSettings, $phoneCheck): User {
                CommissionWallet::lock();
                $this->claimCodes($actor, [$dealerCode, $referCode]);
                $user = (new User)->forceFill([
                    'role' => $role,
                    'dealer_code' => $dealerCode,
                    'refer_code' => $referCode,
                    'name' => trim((string) $input['name']),
                    'email' => $email,
                    'phone' => $phone,
                    'phone_share_approved_by' => $phoneCheck['phone_share_approved_by'],
                    'phone_share_reason' => $phoneCheck['phone_share_reason'],
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
                    $this->seatDealer($user, $place, $path);
                }

                Audit::record($actor->id, 'account.created', (string) $user->id, [
                    'role' => $role,
                    'dealer_code' => $dealerCode,
                    'place_code' => $place->code,
                    'refer_code' => $referCode,
                    'reference_code' => $managementReferral ? $managementSettings['place_code'] : $sponsor?->refer_code,
                    'referred_by_management' => $managementReferral,
                    'reason' => $input['reason'] ?? __('portal.acct_default_onboarding_reason'),
                ]);
                $this->phones->recordShare($actor, $user, $phoneCheck);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException $clash) {
            throw $this->sameMomentRefusal($clash, $role);
        }

        // One-time credentials travel only through the SMS channel — never JSON/Inertia props.
        $credentialsSent = $this->sms->send($user, $plainPassword);
        app(PartnerEventEmail::class)->sendTranslated($user, 'account.created', 'event_emails.account_created_title', 'event_emails.account_created_body');

        return [
            'user' => $user,
            'place_label' => $this->placeLabel($place),
            'commission_consumers' => $this->commissionConsumers($sponsor, $managementReferral),
            'credentials_sent' => $credentialsSent,
        ];
    }

    /**
     * Two people saved at the same moment and the database kept the first. The second
     * gets the reason in plain words instead of a server error.
     */
    private function sameMomentRefusal(UniqueConstraintViolationException $clash, string $role): ValidationException
    {
        preg_match('/unique constraint "([^"]+)"/', $clash->getMessage(), $match);
        $constraint = $match[1] ?? '';

        return ValidationException::withMessages(match (true) {
            str_contains($constraint, 'email') => ['email' => __('portal.acct_val_email_taken')],
            str_contains($constraint, 'phone') => ['phone' => __('portal.acct_val_phone_taken')],
            str_contains($constraint, 'refer_code') => ['refer_code' => __('portal.acct_val_refer_taken')],
            $role === 'sub_dealer' => ['place_code' => __('portal.acct_val_sub_dealer_code_just_used')],
            default => ['place_code' => __('portal.acct_val_place_taken')],
        });
    }

    /**
     * The code a new partner gets on a place: a dealer takes the place code and a
     * sub-dealer takes the next serial in the union.
     *
     * @throws ValidationException when a dealer already holds the place or the code is in use
     */
    public function codeForPlace(User $actor, PlaceCode $place, string $role): string
    {
        if ($role === 'sub_dealer') {
            return $this->nextSubDealerCode($place->code);
        }
        if (AreaDealerAssignment::query()->where('place_code_id', $place->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_taken')]);
        }
        if (User::query()->where('dealer_code', $place->code)->whereNull('closed_at')->exists() || $this->referCodeInUse($place->code, openAccountsOnly: true)) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_dealer_code_taken')]);
        }
        $this->claimCodes($actor, [$place->code]);

        return $place->code;
    }

    /**
     * These codes are about to belong to someone. A closed account gives them up here and
     * takes a retired code, so its history stays with it; a closed account keeps its code until
     * this moment, so an account reopened before then needs no change.
     *
     * @param  list<string>  $codes
     */
    private function claimCodes(User $actor, array $codes): void
    {
        $wanted = array_values(array_unique(array_map(fn (string $code): string => strtoupper(trim($code)), $codes)));
        $closedHolders = User::query()
            ->whereNotNull('closed_at')
            ->where(fn ($query) => $query->whereIn(DB::raw('UPPER(dealer_code)'), $wanted)->orWhereIn(DB::raw('UPPER(refer_code)'), $wanted))
            ->lockForUpdate()
            ->get();
        foreach ($closedHolders as $closed) {
            $givenUp = [];
            foreach (['dealer_code', 'refer_code'] as $column) {
                if (in_array(strtoupper((string) $closed->{$column}), $wanted, true)) {
                    $givenUp[$column] = ['before' => $closed->{$column}, 'after' => $this->retiredCode((string) $closed->{$column}, (int) $closed->id)];
                    $closed->{$column} = $givenUp[$column]['after'];
                }
            }
            $closed->save();
            Audit::record($actor->id, 'account.code_retired', (string) $closed->id, ['changes' => $givenUp]);
        }
        $this->formerCodes->forget($wanted);
    }

    private function retiredCode(string $code, int $userId): string
    {
        $suffix = self::RetiredCodeMark.$userId;

        return substr($code, 0, 40 - strlen($suffix)).$suffix;
    }

    /**
     * The dealer's code follows the place. A referral code the office chose by hand is left
     * alone. Codes the dealer used before stay on record so old cards and links still work.
     *
     * @return array{from_dealer_code: ?string, to_dealer_code: string, from_refer_code: ?string, to_refer_code: ?string}|null
     */
    private function carryDealerCodeToPlace(User $actor, User $dealer, PlaceCode $place): ?array
    {
        $newCode = (string) $place->code;
        $oldDealerCode = (string) $dealer->dealer_code;
        if (strcasecmp($oldDealerCode, $newCode) === 0) {
            return null;
        }
        $this->assertCodeIsFree($dealer, $newCode, 'place_code');

        $oldReferCode = (string) $dealer->refer_code;
        $referFollows = $oldReferCode === '' || strcasecmp($oldReferCode, $oldDealerCode) === 0 || str_contains($oldReferCode, self::RetiredCodeMark);
        $this->claimCodes($actor, [$newCode]);
        $dealer->forceFill(['dealer_code' => $newCode, 'refer_code' => $referFollows ? $newCode : $oldReferCode])->save();
        foreach (array_unique($referFollows ? [$oldDealerCode, $oldReferCode] : [$oldDealerCode]) as $formerCode) {
            if (! str_contains($formerCode, self::RetiredCodeMark)) {
                $this->formerCodes->remember($dealer, $formerCode);
            }
        }

        return [
            'from_dealer_code' => $oldDealerCode !== '' ? $oldDealerCode : null,
            'to_dealer_code' => $newCode,
            'from_refer_code' => $oldReferCode !== '' ? $oldReferCode : null,
            'to_refer_code' => $dealer->refer_code,
        ];
    }

    /**
     * @throws ValidationException when an open account other than this dealer uses the code
     */
    private function assertCodeIsFree(User $dealer, string $code, string $field): void
    {
        $inUse = User::query()
            ->whereKeyNot($dealer->id)
            ->whereNull('closed_at')
            ->where(fn ($query) => $query->whereRaw('UPPER(dealer_code) = ?', [strtoupper($code)])->orWhereRaw('UPPER(refer_code) = ?', [strtoupper($code)]))
            ->exists();
        if ($inUse) {
            throw ValidationException::withMessages([$field => __('portal.acct_val_dealer_code_taken')]);
        }
    }

    /**
     * Gives a reopened dealer back the places closing freed. Another dealer on the home place
     * stops the reopen, because the dealer's code is that place's code; another dealer on an
     * extra place only means that place is not given back.
     *
     * @throws ValidationException when someone else now holds the dealer's home place
     */
    public function returnSeatsOnReopen(User $actor, User $dealer): void
    {
        if ($dealer->role !== 'dealer') {
            return;
        }
        $profile = $this->savedProfile($dealer);
        $homeCode = (string) ($profile['place_code'] ?? '');
        $homePlaceId = $this->recordedHomePlaceId($profile, $homeCode);
        $notReturned = [];

        foreach (array_unique(array_filter([$homePlaceId, ...$this->freedPlaceIds($dealer)])) as $placeId) {
            $place = PlaceCode::query()->find($placeId);
            if (! $place instanceof PlaceCode) {
                continue;
            }
            $otherHolderId = AreaDealerAssignment::query()
                ->where('place_code_id', $placeId)
                ->where('is_active', true)
                ->where('user_id', '!=', $dealer->id)
                ->value('user_id');
            if ($otherHolderId === null) {
                $this->takeSeatBack($dealer, $place);
            } elseif ($placeId === $homePlaceId) {
                throw ValidationException::withMessages(['status' => __('portal.acct_val_reopen_place_taken', [
                    'place' => $place->code,
                    'holder' => User::query()->whereKey($otherHolderId)->value('dealer_code') ?? $place->code,
                ])]);
            } else {
                $notReturned[] = $place->code;
            }
        }

        unset($profile[self::FreedSeatsKey]);
        $dealer->forceFill(['profile' => $profile]);
        if ($homeCode !== '' && str_contains((string) $dealer->dealer_code, self::RetiredCodeMark)) {
            $this->assertCodeIsFree($dealer, $homeCode, 'status');
            $dealer->forceFill([
                'dealer_code' => $homeCode,
                'refer_code' => str_contains((string) $dealer->refer_code, self::RetiredCodeMark) ? $homeCode : $dealer->refer_code,
            ]);
        }
        $dealer->save();
        if ($notReturned !== []) {
            Audit::record($actor->id, 'account.seats_not_returned', (string) $dealer->id, ['places' => $notReturned]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function savedProfile(User $partner): array
    {
        $saved = $partner->getAttribute('profile');

        return is_array($saved) ? $saved : [];
    }

    /**
     * @return list<int> The places closing took from this dealer
     */
    private function freedPlaceIds(User $dealer): array
    {
        return array_values(array_map(intval(...), (array) ($this->savedProfile($dealer)[self::FreedSeatsKey] ?? [])));
    }

    private function takeSeatBack(User $dealer, PlaceCode $place): void
    {
        $seats = AreaDealerAssignment::query()->where('user_id', $dealer->id)->where('place_code_id', $place->id);
        if ((clone $seats)->where('is_active', true)->exists()) {
            return;
        }
        $seat = $seats->latest('id')->first();
        if (! $seat instanceof AreaDealerAssignment) {
            $this->seatDealer($dealer, $place);

            return;
        }
        $seat->territory_key = hash('sha256', $place->level.'|code:'.$place->code);
        $seat->releaseInactiveTerritoryKey();
        $seat->is_active = true;
        $seat->save();
    }

    /**
     * Makes a dealer the holder of a place, so the area shares of that place reach them.
     *
     * @param  array<string, mixed>|null  $path  The place's geography when the caller already has it
     */
    public function seatDealer(User $dealer, PlaceCode $place, ?array $path = null): void
    {
        $path ??= $this->geography->expandFromAnchor($place);
        AreaDealerAssignment::create([
            'user_id' => $dealer->id,
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

    /**
     * The active dealer a typed reference code belongs to. People may type the dealer's
     * refer code or dealer code, with or without the MNHC- prefix, or a code the dealer
     * had before moving.
     */
    public function findSponsor(string $reference): ?User
    {
        $forms = $this->codeForms($reference);

        return User::query()
            ->where(function ($query) use ($forms): void {
                $query->whereIn(DB::raw('UPPER(refer_code)'), $forms)
                    ->orWhereIn(DB::raw('UPPER(dealer_code)'), $forms);
            })
            ->where('role', 'dealer')
            ->where('status', 'active')
            ->orderBy('id')
            ->first() ?? $this->formerCodes->activeHolder($forms);
    }

    /**
     * True when an account already uses this refer code, with or without the MNHC- prefix.
     * A closed account's code can be given to someone else, so it may be left out.
     */
    public function referCodeInUse(string $code, bool $openAccountsOnly = false): bool
    {
        return User::query()
            ->whereIn(DB::raw('UPPER(refer_code)'), $this->codeForms($code))
            ->when($openAccountsOnly, fn ($query) => $query->whereNull('closed_at'))
            ->exists();
    }

    /** True when two typed codes are the same code, with or without the MNHC- prefix. */
    public function isSameCode(string $first, string $second): bool
    {
        return PlaceCode::brandSerial($first) === PlaceCode::brandSerial($second);
    }

    /**
     * A typed code as written, with the MNHC- prefix, and without it.
     *
     * @return list<string>
     */
    private function codeForms(string $code): array
    {
        $typed = strtoupper(trim($code));
        $branded = PlaceCode::brandSerial($typed) ?? $typed;
        $prefix = PlaceCode::BrandPrefix.'-';
        $bare = str_starts_with($branded, $prefix) ? substr($branded, strlen($prefix)) : $branded;

        return array_values(array_unique([$typed, $branded, $bare]));
    }

    public function placeLabel(PlaceCode $place): string
    {
        return $place->code.'-'.PlaceCodeCatalog::localizedLevelLabel($place->level).'-'.$place->name;
    }

    /**
     * Re-anchor a partner's commission geography to a catalog place code.
     * Dealers move the seat of their home place and keep any other seat the office
     * gave them; sub-dealers keep their serial dealer_code while updating union_place_code / place_code.
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
            if ($partner->closed_at !== null) {
                throw ValidationException::withMessages(['place_code' => __('portal.acct_val_closed_dealer_move')]);
            }
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
            $homePlaceId = $this->recordedHomePlaceId($profile, $currentCode);
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
            $freedPlaceIds = $this->freedPlaceIds($locked);
            if ($freedPlaceIds !== []) {
                $profile[self::FreedSeatsKey] = array_values(array_diff($freedPlaceIds, [$homePlaceId]));
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
                    ->whereRaw('place_code_id IS DISTINCT FROM ?', [$place->id])
                    ->when($homePlaceId !== null, fn ($seats) => $seats->where('place_code_id', $homePlaceId))
                    ->update(['is_active' => false, 'territory_key' => null, 'updated_at' => now()]);
                $alreadySeatedThere = AreaDealerAssignment::query()
                    ->where('user_id', $locked->id)
                    ->where('is_active', true)
                    ->where('place_code_id', $place->id)
                    ->exists();
                if (! $alreadySeatedThere) {
                    $this->seatDealer($locked, $place, $path);
                }
            }
            $codeChange = $locked->role === 'dealer' ? $this->carryDealerCodeToPlace($actor, $locked, $place) : null;

            Audit::record($actor->id, 'account.geography_updated', (string) $locked->id, [
                'from_place_code' => $currentCode !== '' ? $currentCode : null,
                'to_place_code' => $place->code,
                'role' => $locked->role,
                'reason' => $reason,
                ...($codeChange ?? []),
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
            if ($codeChange !== null) {
                Audit::notice(
                    $locked->id,
                    __('portal.notice_dealer_code_changed_title', [], $locale),
                    __('portal.notice_dealer_code_changed_body', ['new' => $codeChange['to_dealer_code'], 'old' => $codeChange['from_dealer_code'] ?? '—'], $locale),
                );
            }
            DB::afterCommit(function () use ($locked, $label, $reason, $codeChange): void {
                app(PartnerEventSms::class)->send(
                    $locked,
                    'account.geography_updated',
                    SmsText::make('sms.geography_updated', [
                        'place' => $label,
                        'reason' => $reason,
                    ]),
                );
                if ($codeChange !== null) {
                    app(PartnerEventSms::class)->send($locked, 'account.dealer_code_changed', SmsText::make('sms.dealer_code_changed', ['code' => $codeChange['to_dealer_code']]));
                }
            });
        }, 3);
    }

    /**
     * The place of a partner's home seat, from the saved place code id or else the saved code.
     * Null when the account has neither, as with some older accounts.
     *
     * @param  array<string, mixed>  $profile
     */
    private function recordedHomePlaceId(array $profile, string $currentCode): ?int
    {
        if (is_numeric($profile['place_code_id'] ?? null)) {
            return (int) $profile['place_code_id'];
        }

        return $currentCode !== '' ? PlaceCode::findByCode($currentCode, false)?->id : null;
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
    public function commissionConsumers(?User $sponsor, bool $managementReferral = false): array
    {
        if ($managementReferral) {
            $slot = $this->managementSlot();

            return [
                'direct_referral' => [...$slot, 'rate' => '10%'],
                'd2d_gen2' => [...$slot, 'rate' => '2%'],
                'd2d_gen3' => [...$slot, 'rate' => '1%'],
                'management_fund' => true,
                'unassigned' => [],
            ];
        }
        if (! $sponsor) {
            return [
                'direct_referral' => null,
                'd2d_gen2' => null,
                'd2d_gen3' => null,
                'management_fund' => false,
                'unassigned' => ['direct_referral' => '10%', 'd2d_gen2' => '2%', 'd2d_gen3' => '1%'],
            ];
        }
        $gen2 = $sponsor->sponsor_id ? User::query()->find($sponsor->sponsor_id) : null;
        $gen3 = $gen2?->sponsor_id ? User::query()->find($gen2->sponsor_id) : null;
        $management = $this->managementSlot();
        $gen2Management = ! $gen2 && $this->managementReferral->countsAsManagement($sponsor);
        $gen3Management = $gen2
            ? (! $gen3 && $this->managementReferral->countsAsManagement($gen2))
            : $gen2Management;
        $unassigned = [];
        if (! $gen2 && ! $gen2Management) {
            $unassigned['d2d_gen2'] = '2%';
        }
        if (! $gen3 && ! $gen3Management) {
            $unassigned['d2d_gen3'] = '1%';
        }

        return [
            'direct_referral' => ['id' => $sponsor->id, 'name' => $sponsor->name, 'refer_code' => $sponsor->refer_code, 'rate' => '10%'],
            'd2d_gen2' => $gen2
                ? ['id' => $gen2->id, 'name' => $gen2->name, 'refer_code' => $gen2->refer_code, 'rate' => '2%']
                : ($gen2Management ? [...$management, 'rate' => '2%'] : null),
            'd2d_gen3' => $gen3
                ? ['id' => $gen3->id, 'name' => $gen3->name, 'refer_code' => $gen3->refer_code, 'rate' => '1%']
                : ($gen3Management ? [...$management, 'rate' => '1%'] : null),
            'management_fund' => $gen2Management || $gen3Management,
            'unassigned' => $unassigned,
        ];
    }

    public function blankReferenceUsesManagement(): bool
    {
        $settings = $this->managementReferral->settings();

        return $settings['enabled'] && $settings['auto_blank_reference'] && $settings['place_code'] !== null;
    }

    public function referenceIsManagement(string $code): bool
    {
        return $this->managementReferral->matchesReference($code);
    }

    /** @return array{id: null, name: string, refer_code: ?string, fund: string} */
    private function managementSlot(): array
    {
        $settings = $this->managementReferral->settings();

        return [
            'id' => null,
            'name' => __('portal.acct_management_referrer'),
            'refer_code' => $settings['place_code'],
            'fund' => 'management',
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
            // Only regular serials count; an imported code in another shape would sort last and restart the count.
            ->whereRaw('dealer_code ~ ?', ['-[0-9]{6}$'])
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
        return $this->phones->normalize($phone);
    }

    private function temporaryPassword(): string
    {
        return 'Md'.Str::upper(Str::random(2)).random_int(100000, 999999).'!';
    }
}
