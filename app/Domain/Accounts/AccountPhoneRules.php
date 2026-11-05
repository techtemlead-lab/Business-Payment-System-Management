<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\Audit;
use App\Support\PhoneIdentity;
use Illuminate\Validation\ValidationException;

/**
 * Mobile number rules for every account (owner decisions, 2026-11-05).
 *
 * - Every account must have a valid Bangladeshi mobile number: it is where the sign-in code goes.
 * - A number belongs to one account only. An owner (Super Admin, MD, Chairman) may let one account
 *   share a number another account already uses, with a written reason that goes in the audit log.
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
        $digits = preg_replace('/\D+/', '', is_scalar($phone) ? (string) $phone : '') ?: '';
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

    /**
     * Check a number for an account being created or changed.
     *
     * Returns the share approval to store on the account (both null when the number is not shared).
     *
     * @return array{phone: string, phone_share_approved_by: ?int, phone_share_reason: ?string}
     *
     * @throws ValidationException when the number is invalid, or taken and no owner approved sharing it
     */
    public function check(User $actor, mixed $phone, ?int $accountId = null, mixed $shareReason = null, string $field = 'phone'): array
    {
        $normalized = $this->normalize($phone, $field);
        $holders = $this->holders($normalized, $accountId);
        if ($holders === []) {
            return ['phone' => $normalized, 'phone_share_approved_by' => null, 'phone_share_reason' => null];
        }
        $reason = is_string($shareReason) ? trim($shareReason) : '';
        if (! $actor->owner() || $actor->status !== 'active') {
            throw ValidationException::withMessages([$field => __('portal.acct_val_phone_taken')]);
        }
        if ($reason === '') {
            throw ValidationException::withMessages([$field => __('portal.acct_val_phone_taken_owner')]);
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['phone_share_reason' => __('portal.acct_val_phone_share_reason_long')]);
        }

        return ['phone' => $normalized, 'phone_share_approved_by' => $actor->id, 'phone_share_reason' => $reason];
    }

    /**
     * Audit an owner's approval to share a number, once the account exists.
     *
     * @param  array{phone: string, phone_share_approved_by: ?int, phone_share_reason: ?string}  $checked
     */
    public function recordShare(User $actor, User $account, array $checked): void
    {
        if ($checked['phone_share_approved_by'] === null) {
            return;
        }
        Audit::record($actor->id, 'account.phone_share_approved', (string) $account->id, [
            'phone_last_digits' => substr($checked['phone'], -4),
            'shared_with' => $this->holders($checked['phone'], $account->id),
            'reason' => $checked['phone_share_reason'],
        ]);
    }
}
