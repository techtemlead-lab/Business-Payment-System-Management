<?php

namespace App\Domain\Accounts;

use App\Domain\Security\SecuritySettings;
use App\Models\User;
use App\Support\LocalDigits;
use App\Support\PhoneIdentity;
use Illuminate\Validation\ValidationException;

/**
 * Mobile number rules for every account (owner decisions, 2026-11-05).
 *
 * - Every account must have a valid Bangladeshi mobile number: it is where the sign-in code goes.
 * - Only while the owner's "one number, one account" switch is on (off by default, 2026-11-17):
 *   a number belongs to one account only: no new account may take a number another account has,
 *   not even an owner, until the switch is turned off. Accounts that already share a number keep
 *   working. With the switch off, any number of accounts may share a number.
 * - Numbers are stored as 01XXXXXXXXX, and compared in the same normalised form login uses, so
 *   "+8801711…" and "01711…" count as the same number.
 */
final class AccountPhoneRules
{
    /**
     * A valid Bangladeshi mobile number in the stored form 01XXXXXXXXX.
     *
     * @throws ValidationException when the number is missing or not a Bangladeshi mobile number
     */
    public function normalize(mixed $phone, string $field = 'phone'): string
    {
        $digits = preg_replace('/\D+/', '', is_scalar($phone) ? LocalDigits::latin((string) $phone) : '') ?: '';
        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            $digits = substr($digits, 2);
        }
        if (! preg_match('/^01[3-9]\d{8}$/D', $digits)) {
            throw ValidationException::withMessages([$field => __('portal.acct_phone_bd_invalid')]);
        }

        return $digits;
    }

    /** True when the value is a valid Bangladeshi mobile number. */
    public function isValid(mixed $phone): bool
    {
        try {
            $this->normalize($phone);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * Ids of other accounts that already use this number.
     *
     * @return list<int>
     */
    public function holders(string $phone, ?int $ignoreUserId = null): array
    {
        $identity = PhoneIdentity::normalize($phone);
        if ($identity === null) {
            return [];
        }

        return User::query()
            ->whereNotNull('phone')
            ->whereRaw("phone ~ '^[0-9[:space:]()+.-]+$'")
            ->whereRaw('('.PhoneIdentity::SQL.') = ?', [$identity])
            ->when($ignoreUserId !== null, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** True when the owner switched "one number, one account" on. Off by default. */
    public function enforced(): bool
    {
        return app(SecuritySettings::class)->oneAccountPerIdentityEnforced();
    }

    /** True when another account holds this number and the owner's switch makes that a problem. */
    public function isTaken(string $phone, ?int $ignoreUserId = null): bool
    {
        return $this->enforced() && $this->holders($phone, $ignoreUserId) !== [];
    }

    /**
     * A valid number for an account being created or whose number is being changed.
     *
     * While the owner's switch is on, a number another account already holds is refused for
     * everyone, owners included, until the switch is turned off. Accounts that already share a
     * number keep working: callers skip this check when an account keeps its own number.
     *
     * @throws ValidationException when the number is invalid, or taken while the switch is on
     */
    public function available(mixed $phone, ?int $accountId = null, string $field = 'phone'): string
    {
        $normalized = $this->normalize($phone, $field);
        if ($this->isTaken($normalized, $accountId)) {
            throw ValidationException::withMessages([$field => __('portal.acct_val_phone_taken')]);
        }

        return $normalized;
    }
}
