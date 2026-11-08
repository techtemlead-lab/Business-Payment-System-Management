<?php

namespace App\Domain\Clinic;

use App\Models\User;

/**
 * Who may write prescriptions, who may switch prescribing on for a partner, and who sees
 * which patients.
 *
 * Owner decisions (2026-11-08): prescribing is OFF for every dealer and sub-dealer until the
 * owner, or someone the owner nominates, checks their registration offline and turns it on.
 * The owner-doctor (Super Admin) always has it. Office accounts (MD, Chairman, any staff) may
 * prescribe and dispense from company stock only when switched on per account. A patient and
 * their prescriptions are seen only by the prescriber who saved them, not even the owner.
 */
final class PrescriptionAccess
{
    /** Staff permission the owner grants to the people who may verify prescribers. */
    public const VerifyPermission = 'prescriber_verify';

    /** The MADINA_PRESCRIPTIONS feature flag. */
    public static function enabled(): bool
    {
        return (bool) config('madina.prescriptions.enabled', true);
    }

    /** The owner-doctor: prescribes by default and dispenses from company stock. */
    public static function isOwnerDoctor(User $user): bool
    {
        return $user->role === 'super_admin' && $user->status === 'active';
    }

    public static function isPartner(User $user): bool
    {
        return in_array($user->role, ['dealer', 'sub_dealer'], true);
    }

    /** MD, Chairman or any staff: the accounts with a per-account company-stock switch. */
    public static function isOfficeAccount(User $user): bool
    {
        return $user->role !== 'super_admin' && ! self::isPartner($user) && $user->staff();
    }

    /** May open the prescriptions section and write prescriptions. */
    public static function canPrescribe(User $user): bool
    {
        if (! self::enabled() || $user->status !== 'active') {
            return false;
        }
        if (self::usesCompanyStock($user)) {
            return true;
        }

        return self::isPartner($user) && ! $user->usingStaffWorkspace() && (bool) $user->can_prescribe;
    }

    /** Prescribes from company products and dispenses them from company stock. */
    public static function usesCompanyStock(User $user): bool
    {
        if (! self::enabled() || $user->status !== 'active') {
            return false;
        }

        return self::isOwnerDoctor($user) || (self::isOfficeAccount($user) && (bool) $user->can_prescribe);
    }

    /**
     * May turn prescribing on or off for a partner: the owners (Super Admin, MD, Chairman)
     * and staff the owner gave the "prescriber_verify" permission.
     */
    public static function canVerify(User $user): bool
    {
        if (! self::enabled() || $user->status !== 'active') {
            return false;
        }

        return $user->owner() || ($user->staff() && $user->allows(self::VerifyPermission));
    }
}
