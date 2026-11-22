<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\LocalDigits;
use Illuminate\Validation\ValidationException;

/**
 * One person, one account (client, 2026-11-22): the same NID number or birth-certificate number may not open a second
 * account, besides the mobile number and the email.
 *
 * Both numbers are stored encrypted, so they cannot be compared in the database. Each account therefore also keeps a
 * one-way key of its number (an HMAC made with the application key, in users.nid_hash and
 * users.birth_certificate_hash). The key shows nothing of the number and cannot be turned back into it; two accounts
 * with the same number have the same key. The keys are filled by the User model each time an account is saved.
 *
 * The number is first reduced to its letters and digits, with Bangla digits turned into 0-9, so "1985 1234-567"
 * and "১৯৮৫১২৩৪৫৬৭" count as the same number.
 */
final class IdentityKeys
{
    public const Nid = 'nid';

    public const BirthCertificate = 'birth_certificate';

    /** The key of a number, or null when there is no number. */
    public static function key(string $type, mixed $number): ?string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', LocalDigits::latin(is_scalar($number) ? (string) $number : '')));
        if ($clean === '') {
            return null;
        }

        return hash_hmac('sha256', $type.':'.$clean, (string) config('app.key'));
    }

    /** The id of another account that holds this number, or null. */
    public static function holder(string $type, mixed $number, ?int $ignoreUserId = null): ?int
    {
        $key = self::key($type, $number);
        if ($key === null) {
            return null;
        }
        $id = User::query()
            ->where($type === self::Nid ? 'nid_hash' : 'birth_certificate_hash', $key)
            ->when($ignoreUserId !== null, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Refuses a number that another account already holds, with a plain message on the box it was typed in.
     *
     * @throws ValidationException
     */
    public static function refuseDuplicates(mixed $nid, mixed $birthCertificate = null, ?int $ignoreUserId = null): void
    {
        if (self::holder(self::Nid, $nid, $ignoreUserId) !== null) {
            throw ValidationException::withMessages(['nid' => __('portal.acct_val_nid_taken')]);
        }
        if (self::holder(self::BirthCertificate, $birthCertificate, $ignoreUserId) !== null) {
            throw ValidationException::withMessages(['birth_certificate_number' => __('portal.acct_val_birth_cert_taken')]);
        }
    }
}
