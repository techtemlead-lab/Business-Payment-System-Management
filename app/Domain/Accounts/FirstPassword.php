<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\Audit;

/**
 * A first password that does not depend on email or SMS. The staff member who creates or approves an
 * account is shown a temporary password once, gives it to the person, and the person must choose their
 * own at first sign-in. The email reset link stays as an extra way in.
 */
final class FirstPassword
{
    /** Letters and digits that cannot be mistaken for each other when read aloud or written down. */
    private const Alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function make(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::Alphabet[random_int(0, strlen(self::Alphabet) - 1)];
        }

        // "Md" keeps the same look as passwords sent by SMS; the digit and the mark satisfy the password rule.
        return 'Md'.$chars.random_int(10, 99).'!';
    }

    /**
     * Gives the account a fresh temporary password and marks it as "must change". Returns the plain
     * password, which the caller shows once and never stores anywhere else.
     */
    public function issue(User $user, ?User $actor = null): string
    {
        $plain = self::make();
        $user->forceFill([
            'password' => $plain,
            'must_change_password' => true,
            'password_managed_by' => User::PasswordManagedByAdmin,
            'admin_password_cipher' => $user->recoverablePasswordCopy($plain),
        ])->save();
        Audit::record($actor?->id, 'account.first_password_issued', (string) $user->id, ['role' => $user->role]);

        return $plain;
    }

    /**
     * The one-time line staff see after the account is made. Plain words for someone who is not technical.
     */
    /** When the office typed the password itself it is not shown again; the partner may change it later. */
    public static function typedNotice(User $user): string
    {
        return __('leftovers_office.first_password_typed_notice', [
            'name' => $user->name,
            'login' => filled($user->phone) ? $user->phone : (string) $user->email,
        ]);
    }

    public static function notice(User $user, string $plain): string
    {
        return __('joining.first_password_notice', [
            'name' => $user->name,
            'login' => filled($user->phone) ? $user->phone : (string) $user->email,
            'password' => $plain,
        ]);
    }
}
