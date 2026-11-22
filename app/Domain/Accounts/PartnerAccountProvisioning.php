<?php

namespace App\Domain\Accounts;

use App\Domain\Auth\SignInGuard;
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
use App\Support\ReasonFields;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PartnerAccountProvisioning
{
    /** Who can refer a new partner. */
    public const SponsorRoles = ['dealer', 'sub_dealer'];

    /** Marks a closed account's code after someone else was given the real one. */
    public const RetiredCodeMark = '-CLOSED-';

    /** The places closing took from a dealer, kept on the profile for when it reopens. */
    public const FreedSeatsKey = 'seats_freed_on_close';

    /** The request fields of the four area places, from the top of the country down. */
    public const AreaChainFields = [
        PlaceCode::LevelDivision => 'area_division_id',
        PlaceCode::LevelDistrict => 'area_district_id',
        PlaceCode::LevelUpazila => 'area_upazila_id',
        PlaceCode::LevelUnion => 'area_union_id',
    ];

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
     * @return array{user: User, place_label: ?string, commission_consumers: array<string, mixed>, credentials_sent: bool, password_link_sent: bool, first_password: string, password_typed: bool}
     */
    public function create(User $actor, array $input): array
    {
        $role = (string) ($input['role'] ?? '');
        if (! in_array($role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['role' => __('portal.acct_val_role')]);
        }

        $phone = $this->phones->available($input['phone'] ?? '');
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $referenceCode = strtoupper(trim((string) ($input['reference_code'] ?? '')));
        $identityType = (string) ($input['identity_type'] ?? 'birth_certificate');
        if (! in_array($identityType, ['birth_certificate', 'nid'], true)) {
            throw ValidationException::withMessages(['identity_type' => __('portal.acct_val_identity_type')]);
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => __('portal.acct_val_email_taken')]);
        }
        // One person, one account: the same NID or birth-certificate number never opens a second one.
        IdentityKeys::refuseDuplicates($input['nid'] ?? null, $input['birth_certificate_number'] ?? null);

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
        // The form asks the kind of dealer first (division, district, upazila or union dealer); the
        // place must be of that level, so a district dealer is never seated on an upazila by mistake.
        $dealerLevel = trim((string) ($input['dealer_level'] ?? ''));
        if ($role === 'dealer' && $dealerLevel !== '' && $place->level !== $dealerLevel) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_not_dealer_level', [
                'kind' => in_array($dealerLevel, PlaceCode::AssignmentLevels, true) ? __('portal.acct_dealer_kind_'.$dealerLevel) : $dealerLevel,
            ])]);
        }
        $permanentAddress = $this->permanentAddress($input['permanent_place_id'] ?? null);
        $areaChain = $this->areaChain($input, $place);

        if ($role === 'dealer') {
            $taken = AreaDealerAssignment::query()
                ->where('place_code_id', $place->id)
                ->where('is_active', true)
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['place_code' => __('portal.acct_val_place_taken')]);
            }
            $dealerCode = PlaceCode::brandSerial(trim((string) ($input['dealer_code'] ?? ''))) ?: $place->code;
            if (User::query()->whereIn(DB::raw('UPPER(dealer_code)'), PlaceCode::serialForms($dealerCode))->whereNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['dealer_code' => __('portal.acct_val_dealer_code_taken')]);
            }
        } else {
            $dealerCode = $this->nextSubDealerCode($place->code);
        }

        // A partner's dealer or sub-dealer code is also their refer code; there is no second code.
        $referCode = $dealerCode;
        $ownCodeBox = $role === 'dealer' ? 'dealer_code' : 'place_code';
        if ($referenceCode !== '' && $this->isSameCode($referenceCode, $referCode)) {
            throw ValidationException::withMessages(['reference_code' => __('portal.acct_val_reference_same_as_refer')]);
        }
        if ($this->managementReferral->matchesReference($referCode)) {
            throw ValidationException::withMessages([$ownCodeBox => __('portal.acct_val_refer_is_management')]);
        }
        if ($this->referCodeInUse($referCode, openAccountsOnly: true)) {
            throw ValidationException::withMessages([$ownCodeBox => __('portal.acct_val_refer_taken')]);
        }

        $path = $this->geography->expandFromAnchor($place);
        // The business area shown for the partner: the four places picked, when they were picked.
        $areaNames = $areaChain === null ? $path : array_map(fn (array $chosen): string => $chosen['name'], $areaChain);
        // A password typed by the office is used as it is; left empty, one is made. Never logged or audited.
        $typedPassword = trim((string) ($input['password'] ?? ''));
        if ($typedPassword !== '' && (mb_strlen($typedPassword) < 8 || app(SignInGuard::class)->isTooEasy($typedPassword))) {
            throw ValidationException::withMessages(['password' => __('security.password_too_easy')]);
        }
        $plainPassword = $typedPassword !== '' ? $typedPassword : $this->temporaryPassword();
        $shopName = trim((string) ($input['shop_name'] ?? $input['pharmacy'] ?? ''));
        $profile = [
            'name_bn' => trim((string) ($input['name_bn'] ?? '')),
            'father_name' => trim((string) ($input['father_name'] ?? '')),
            'mother_name' => trim((string) ($input['mother_name'] ?? '')),
            'occupation' => trim((string) ($input['occupation'] ?? '')),
            'village' => trim((string) ($input['village'] ?? '')),
            'post_office' => trim((string) ($input['post_office'] ?? '')),
            // The place code decides the area. A typed or left-over name is never taken: a dealer on an
            // upazila code has no union, and must not get one from another upazila.
            'upazila' => (string) ($areaNames['upazila'] ?? ''),
            'union' => (string) ($areaNames['union'] ?? ''),
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
            ...$permanentAddress,
            ...($areaChain === null ? [] : [PlaceGeographyResolver::AreaChainKey => $areaChain]),
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
            $user = DB::transaction(function () use ($actor, $input, $role, $phone, $email, $referCode, $sponsor, $place, $path, $areaNames, $areaChain, $dealerCode, $profile, $plainPassword, $typedPassword, $managementReferral, $managementSettings): User {
                CommissionWallet::lock();
                // Checked again inside the lock that every account creation passes through, so two at the same moment cannot both win.
                IdentityKeys::refuseDuplicates($input['nid'] ?? null, $input['birth_certificate_number'] ?? null);
                if ($this->phones->enforced() && $this->phones->holders($phone) !== []) {
                    throw ValidationException::withMessages(['phone' => __('portal.acct_val_phone_taken')]);
                }
                $this->claimCodes($actor, [$dealerCode]);
                $user = (new User)->forceFill([
                    'role' => $role,
                    'dealer_code' => $dealerCode,
                    'refer_code' => $referCode,
                    'name' => trim((string) $input['name']),
                    'email' => $email,
                    'phone' => $phone,
                    'division' => $areaNames['division'] ?? ($input['division'] ?? null),
                    'district' => $areaNames['district'] ?? ($input['district'] ?? null),
                    'sponsor_id' => $sponsor?->id,
                    'nid' => trim((string) ($input['nid'] ?? '')) ?: null,
                    'latitude' => isset($input['latitude']) && $input['latitude'] !== '' ? (float) $input['latitude'] : null,
                    'longitude' => isset($input['longitude']) && $input['longitude'] !== '' ? (float) $input['longitude'] : null,
                    'directory_visible' => ! empty($input['directory_visible']),
                    'status' => in_array(($status = (string) ($input['status'] ?? 'active')), ['active', 'suspended'], true) ? $status : 'active',
                    'password' => $plainPassword,
                    // A made password must be changed at first sign-in; one the office chose is used as it is.
                    'must_change_password' => $typedPassword === '',
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
                    'area_chain' => $areaChain === null ? null : array_map(fn (array $chosen): string => $chosen['code'], $areaChain),
                    'reason' => $input['reason'] ?? __('portal.acct_default_onboarding_reason'),
                ]);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException $clash) {
            throw $this->sameMomentRefusal($clash, $role);
        }

        // The username and first password go by SMS and, when the person has an email address, in the
        // "account ready" email as well (client, 2026-11-22): never through JSON/Inertia props or the queue.
        $smsSent = $this->sms->send($user, $plainPassword);
        $welcomeMailed = $this->sms->sendWelcomeEmail($user, $plainPassword);
        $credentialsSent = $smsSent || $welcomeMailed;
        $passwordLinkSent = ! $credentialsSent && $this->sendPasswordLink($user);
        if (! $welcomeMailed) {
            app(PartnerEventEmail::class)->sendTranslated($user, 'account.created', 'event_emails.account_created_title', 'event_emails.account_created_body');
        }

        return [
            'user' => $user,
            'place_label' => $this->placeLabel($place),
            'commission_consumers' => $this->commissionConsumers($sponsor, $managementReferral),
            'credentials_sent' => $credentialsSent,
            'password_link_sent' => $passwordLinkSent,
            'first_password' => $plainPassword,
            'password_typed' => $typedPassword !== '',
        ];
    }

    /**
     * The partner's permanent (home) address, kept apart from the business area that sets the code.
     * The office picks the places down to the upazila, or the union; the names are read from the
     * place list by walking up from the picked place, never down to a base place, so a home on an
     * upazila is never given a union nobody chose. Nothing chosen: nothing is saved. The account
     * editor uses the same rule when the office changes a partner's permanent address.
     *
     * @return array<string, string> The profile keys to save
     *
     * @throws ValidationException when the place is unknown or not an upazila or union
     */
    public function permanentAddress(mixed $placeId): array
    {
        if ($placeId === null || $placeId === '') {
            return [];
        }
        $place = is_numeric($placeId) ? PlaceCode::query()->whereKey((int) $placeId)->where('is_active', true)->first() : null;
        if (! $place instanceof PlaceCode) {
            throw ValidationException::withMessages(['permanent_place_id' => __('portal.acct_val_home_place_unknown')]);
        }
        if (! in_array($place->level, [PlaceCode::LevelUpazila, PlaceCode::LevelUnion], true)) {
            throw ValidationException::withMessages(['permanent_place_id' => __('portal.acct_val_home_place_level')]);
        }

        $names = [];
        $cursor = $place;
        $guard = 0;
        while ($cursor instanceof PlaceCode && $guard++ < 6) {
            $names[$cursor->level] ??= trim((string) $cursor->name);
            $cursor = $cursor->parent_id ? PlaceCode::query()->whereKey($cursor->parent_id)->first() : null;
        }
        foreach ([PlaceCode::LevelDivision, PlaceCode::LevelDistrict, PlaceCode::LevelUpazila] as $level) {
            if (($names[$level] ?? '') === '') {
                throw ValidationException::withMessages(['permanent_place_id' => __('portal.acct_val_home_place_unknown')]);
            }
        }

        return [
            'permanent_division' => $names[PlaceCode::LevelDivision],
            'permanent_district' => $names[PlaceCode::LevelDistrict],
            'permanent_upazila' => $names[PlaceCode::LevelUpazila],
            'permanent_union' => $names[PlaceCode::LevelUnion] ?? '',
            'permanent_place_id' => (string) $place->id,
        ];
    }

    /**
     * The four area places picked one by one (division, district, upazila, union): where the
     * area commission of the partner's own purchases goes (client, 2026-11-22). Each must be an
     * active place with a code, of its own level, each the child of the one above, and the place
     * of the seat's level must be the seat itself (a district dealer's district is his code's
     * place). Nothing given: null, and older ways of adding a partner keep the base places.
     *
     * @param  array<string, mixed>  $input  The request fields area_division_id … area_union_id
     * @return array<string, array{id: int, code: string, name: string}>|null level => place
     *
     * @throws ValidationException with a plain message on the first wrong box
     */
    public function areaChain(array $input, ?PlaceCode $seat): ?array
    {
        $given = [];
        foreach (self::AreaChainFields as $level => $field) {
            $value = $input[$field] ?? null;
            $given[$level] = $value === null || $value === '' ? null : $value;
        }
        if (array_filter($given, fn (mixed $value): bool => $value !== null) === []) {
            return null;
        }
        foreach ($given as $level => $value) {
            if ($value === null) {
                throw ValidationException::withMessages([self::AreaChainFields[$level] => __('portal.acct_val_area_'.$level.'_required')]);
            }
        }
        $ids = array_map(fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, $given);
        $places = PlaceCode::query()->whereIn('id', array_values(array_filter($ids)))->get()->keyBy('id');

        $chain = [];
        $parentId = null;
        foreach (self::AreaChainFields as $level => $field) {
            $place = $places->get($ids[$level]);
            if (! $place instanceof PlaceCode || $place->level !== $level || ! $place->isReadyForAssignment()) {
                throw ValidationException::withMessages([$field => __('portal.acct_val_area_place_unknown')]);
            }
            if ($parentId !== null && (int) $place->parent_id !== $parentId) {
                throw ValidationException::withMessages([$field => __('portal.acct_val_area_chain_broken')]);
            }
            $parentId = (int) $place->id;
            $chain[$level] = ['id' => (int) $place->id, 'code' => (string) $place->code, 'name' => trim((string) $place->name)];
        }
        if ($seat instanceof PlaceCode && isset($chain[$seat->level]) && $chain[$seat->level]['id'] !== (int) $seat->id) {
            throw ValidationException::withMessages(['place_code' => __('portal.acct_val_area_seat_mismatch')]);
        }

        return $chain;
    }

    /**
     * The place a partner is seated on today: his most specific active dealer seat, else the
     * place saved on the profile (a sub-dealer's union). Null when he has no place yet.
     */
    public function seatPlaceOf(User $partner): ?PlaceCode
    {
        $seatPlaceId = AreaDealerAssignment::query()
            ->where('user_id', $partner->id)
            ->where('is_active', true)
            ->whereNotNull('place_code_id')
            ->orderByRaw("CASE level WHEN 'union' THEN 1 WHEN 'upazila' THEN 2 WHEN 'district' THEN 3 WHEN 'division' THEN 4 ELSE 5 END")
            ->value('place_code_id');
        $placeId = $seatPlaceId ?? PlaceGeographyResolver::profilePlaceId($partner);
        if ($placeId !== null) {
            return PlaceCode::query()->whereKey($placeId)->first();
        }
        $code = trim((string) data_get($partner->profile, 'place_code', ''));

        return $code !== '' ? PlaceCode::findByCode($code) : null;
    }

    /** The account already exists by now, so a mail problem must not turn into an error page. */
    private function sendPasswordLink(User $user): bool
    {
        try {
            return Password::sendResetLink(['email' => $user->email]) === Password::ResetLinkSent;
        } catch (Throwable $exception) {
            Log::warning('account.created.password_link_failed', ['user_id' => $user->id, 'error' => $exception->getMessage()]);

            return false;
        }
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
        if (User::query()->whereIn(DB::raw('UPPER(dealer_code)'), PlaceCode::serialForms($place->code))->whereNull('closed_at')->exists() || $this->referCodeInUse($place->code, openAccountsOnly: true)) {
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
        // Every spelling, so a closed account still holding the older MNHC-… form gives it up too.
        $wanted = array_values(array_unique(array_merge([], ...array_map(PlaceCode::serialForms(...), $codes))));
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
     * The dealer's code follows the place, and it is also their refer code, so a code the
     * office once chose by hand goes too. Codes used before stay on record for old cards and links.
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
        $this->claimCodes($actor, [$newCode]);
        $dealer->forceFill(['dealer_code' => $newCode, 'refer_code' => $newCode])->save();
        $this->rememberFormerCodes($dealer, [$oldDealerCode, $oldReferCode], $newCode);

        return [
            'from_dealer_code' => $oldDealerCode !== '' ? $oldDealerCode : null,
            'to_dealer_code' => $newCode,
            'from_refer_code' => $oldReferCode !== '' ? $oldReferCode : null,
            'to_refer_code' => $dealer->refer_code,
        ];
    }

    /**
     * The office corrected a partner's code by hand. The refer code is the same code, so the
     * two change together, and what they used before stays on record for old cards and links.
     *
     * @return array{dealer_code: string, refer_code: string} The columns to save
     *
     * @throws ValidationException when an open account other than this partner uses the code
     */
    public function correctCode(User $actor, User $partner, string $newCode): array
    {
        $oldCodes = [(string) $partner->dealer_code, (string) $partner->refer_code];
        if (strcasecmp($oldCodes[0], $newCode) !== 0) {
            $this->assertCodeIsFree($partner, $newCode, 'dealer_code');
            $this->claimCodes($actor, [$newCode]);
        }
        $this->rememberFormerCodes($partner, $oldCodes, $newCode);

        return ['dealer_code' => $newCode, 'refer_code' => $newCode];
    }

    /**
     * @param  list<string>  $oldCodes  The codes the partner held until now
     */
    private function rememberFormerCodes(User $partner, array $oldCodes, string $newCode): void
    {
        foreach (array_unique(array_map(strtoupper(...), $oldCodes)) as $formerCode) {
            if ($formerCode !== strtoupper($newCode) && ! str_contains($formerCode, self::RetiredCodeMark)) {
                $this->formerCodes->remember($partner, $formerCode);
            }
        }
    }

    /**
     * @throws ValidationException when an open account other than this dealer uses the code
     */
    private function assertCodeIsFree(User $dealer, string $code, string $field): void
    {
        $forms = PlaceCode::serialForms($code);
        $inUse = User::query()
            ->whereKeyNot($dealer->id)
            ->whereNull('closed_at')
            ->where(fn ($query) => $query->whereIn(DB::raw('UPPER(dealer_code)'), $forms)->orWhereIn(DB::raw('UPPER(refer_code)'), $forms))
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
            $dealer->forceFill(['dealer_code' => $homeCode, 'refer_code' => $homeCode]);
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
            'monthly_quota' => CommissionPolicy::standardAreaQuota($place->level),
            'territory_key' => hash('sha256', $place->level.'|code:'.$place->code),
            'is_active' => true,
        ]);
    }

    /**
     * The active dealer or sub-dealer a typed reference code belongs to. People may type their
     * refer code or dealer code, with or without the MNHC prefix, in the older MNHC-… spelling,
     * or a code a dealer had before moving.
     */
    public function findSponsor(string $reference): ?User
    {
        $forms = PlaceCode::serialForms($reference);

        return User::query()
            ->where(function ($query) use ($forms): void {
                $query->whereIn(DB::raw('UPPER(refer_code)'), $forms)
                    ->orWhereIn(DB::raw('UPPER(dealer_code)'), $forms);
            })
            ->whereIn('role', self::SponsorRoles)
            ->where('status', 'active')
            ->orderBy('id')
            ->first() ?? $this->formerCodes->activeHolder($forms);
    }

    /**
     * True when an account already uses this refer code in any spelling (MNHC100, MNHC-100, 100).
     * A closed account's code can be given to someone else, so it may be left out.
     */
    public function referCodeInUse(string $code, bool $openAccountsOnly = false): bool
    {
        return User::query()
            ->whereIn(DB::raw('UPPER(refer_code)'), PlaceCode::serialForms($code))
            ->when($openAccountsOnly, fn ($query) => $query->whereNull('closed_at'))
            ->exists();
    }

    /** True when two typed codes are the same code in any spelling (MNHC100, MNHC-100, 100). */
    public function isSameCode(string $first, string $second): bool
    {
        return PlaceCode::brandSerial($first) === PlaceCode::brandSerial($second);
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
                    'reason' => ReasonFields::shown($reason, $locale),
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
                        'reason' => ReasonFields::shown($reason, SmsText::Locale),
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
            'fund' => $settings['route_referral_slices'] ? 'management' : 'company_profit',
        ];
    }

    /**
     * The next sub-dealer serial in a union: the union code, one hyphen, six digits,
     * e.g. MNHC100001-000001. Serials saved in the older MNHC-100001-000001 or bare
     * 100001-000001 spelling still count, so numbering never restarts.
     */
    public function nextSubDealerCode(string $unionCode): string
    {
        $digits = PlaceCode::codeDigits($unionCode);
        if ($digits === null) {
            $prefix = rtrim(strtoupper(trim($unionCode)), '-').'-';
            $prefixes = [$prefix];
            $serialPattern = '^'.preg_quote($prefix).'[0-9]{6}$';
        } else {
            $prefix = PlaceCode::BrandPrefix.$digits.'-';
            $prefixes = array_map(fn (string $form): string => $form.'-', PlaceCode::storedCodeForms($digits));
            $serialPattern = '^('.PlaceCode::BrandPrefix.'-?)?'.$digits.'-[0-9]{6}$';
        }
        $latest = User::query()
            ->where('role', 'sub_dealer')
            ->where(function ($q) use ($prefixes): void {
                foreach ($prefixes as $form) {
                    $q->orWhere('dealer_code', 'like', $form.'%');
                }
            })
            // Only regular serials count; an imported code in another shape would restart the count.
            ->whereRaw('dealer_code ~ ?', [$serialPattern])
            ->orderByRaw('RIGHT(dealer_code, 6) DESC')
            ->value('dealer_code');
        $next = is_string($latest) ? ((int) substr($latest, -6)) + 1 : 1;

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
