<?php

namespace App\Domain\Auth;

use App\Models\PlaceCode;
use App\Models\User;
use App\Support\PhoneIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Sign-in limits that cannot be dodged by typing the same account in another way.
 *
 * - Every way of writing one partner code (MNHC1001, "mnhc 1001", 1001, MNHC--1001) and one phone
 *   number shares one counter, and once the account is found the counter is the account itself.
 * - Each internet address has a failure counter across all accounts. Once it trips, that address
 *   must answer the picture captcha on every try, even while the owner keeps the captcha off for
 *   everyone else (older partners find the letters hard, so it is not shown to normal sign-ins).
 * - When no account matches, a password check still runs against a dummy hash, so the answer
 *   takes as long as for a real account and does not reveal which accounts exist.
 */
final class SignInGuard
{
    /** Failed tries from one address, over all accounts, before the captcha is switched on for it. */
    public const AddressFailuresBeforeCaptcha = 20;

    public const AddressFailureWindowSeconds = 600;

    /** Wrong tries for one account from one address per minute, and for one account from anywhere per hour. */
    public const AccountTriesPerAddress = 5;

    public const AccountTriesPerHour = 20;

    private static ?string $dummyHash = null;

    /** The captcha is shown when the owner turned it on for everyone, or this address failed too often. */
    public function captchaRequired(Request $request): bool
    {
        return (bool) config('madina.login_captcha.enabled', false)
            || RateLimiter::attempts($this->addressKey($request)) >= self::AddressFailuresBeforeCaptcha;
    }

    public function recordAddressFailure(Request $request): void
    {
        RateLimiter::hit($this->addressKey($request), self::AddressFailureWindowSeconds);
    }

    /**
     * One spelling for every way the same sign-in name can be typed: email in lower case, a phone
     * number as 8801XXXXXXXXX, a partner code in its stored MNHC form, anything else upper-cased
     * without spaces.
     */
    public function normalizedIdentity(string $typed): string
    {
        $typed = trim($typed);
        if (filter_var($typed, FILTER_VALIDATE_EMAIL) !== false) {
            return 'e:'.Str::lower($typed);
        }
        $phone = PhoneIdentity::normalize($typed);
        if ($phone !== null) {
            return 'p:'.$phone;
        }
        $forms = PlaceCode::serialForms($typed);
        // serialForms lists [as typed, MNHC…, MNHC-…, bare]; the second entry is the stored form.
        $canonical = count($forms) > 1 ? $forms[1] : (string) preg_replace('/\s+/u', '', Str::upper($typed));

        return 'c:'.$canonical;
    }

    /** The account-wide counter: the matched account when there is one, else the normalised name. */
    public function accountKey(?User $account, string $typed): string
    {
        return 'login-account|'.($account ? 'u'.$account->id : $this->normalizedIdentity($typed));
    }

    public function accountAddressKey(?User $account, string $typed, Request $request): string
    {
        return $this->accountKey($account, $typed).'|'.$request->ip();
    }

    /**
     * Checks the password in about the same time whether or not an account was found.
     */
    public function passwordMatches(?User $account, string $password): bool
    {
        if (! $account) {
            Hash::check($password, $this->dummyHash());

            return false;
        }

        return Hash::check($password, (string) $account->password);
    }

    /**
     * A password the person may not choose: one of the most-used PINs and words, or their own phone
     * number or partner code. Digits-only passwords stay allowed (owner rule); this list is the only
     * check beyond the minimum length.
     */
    public function isTooEasy(string $password, ?User $account = null): bool
    {
        $plain = trim($password);
        $folded = Str::lower($plain);
        if (in_array($folded, self::CommonPasswords, true)) {
            return true;
        }
        if (preg_match('/^(\d)\1+$/', $plain) === 1 || $this->isRun($plain)) {
            return true;
        }
        if (! $account) {
            return false;
        }
        $phone = PhoneIdentity::normalize((string) $account->phone);
        $digits = (string) preg_replace('/[\s+\-]+/', '', $plain);
        if ($phone !== null && ctype_digit($digits) && strlen($digits) >= 8) {
            // 8801712345678, 01712345678, 1712345678 and its last eight digits all count as the phone.
            $national = substr($phone, 3);
            if (in_array($digits, [$phone, '0'.$national, $national, substr($phone, -8)], true)) {
                return true;
            }
        }
        $code = (string) $account->dealer_code;
        if ($code !== '') {
            $codeForms = array_map(fn (string $form): string => Str::lower(str_replace('-', '', $form)), PlaceCode::serialForms($code));
            if (in_array(str_replace([' ', '-'], '', $folded), $codeForms, true)) {
                return true;
            }
        }

        return false;
    }

    /** 12345678, 87654321, 23456789 and the like. */
    private function isRun(string $plain): bool
    {
        if (preg_match('/^\d{6,}$/', $plain) !== 1) {
            return false;
        }

        return str_contains('01234567890123456789', $plain) || str_contains('98765432109876543210', $plain);
    }

    private function addressKey(Request $request): string
    {
        return 'login-address-failures|'.$request->ip();
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(40));
    }

    /**
     * The most-used PINs and passwords seen in public leak lists, lower-cased. Same-digit and
     * straight runs (111111, 12345678) are caught by isTooEasy() as well.
     */
    public const CommonPasswords = [
        '12345678', '123456789', '1234567890', '0123456789', '87654321', '987654321', '11223344', '12341234',
        '12121212', '11112222', '12344321', '11221122', '13579135', '24682468', '01234567', '10203040',
        '1q2w3e4r', '1qaz2wsx', 'qwertyui', 'qwerty123', 'qwerty12', 'asdfghjk', 'zxcvbnm1', 'abcd1234',
        'abc12345', 'password', 'password1', 'password123', 'passw0rd', 'p@ssw0rd', 'iloveyou', 'sunshine',
        'princess', 'football', 'baseball', 'welcome1', 'welcome123', 'admin123', 'administrator', 'letmein1',
        'monkey123', 'dragon12', 'master12', 'superman', 'trustno1', '11111111', '00000000', '88888888',
        '99999999', '22222222', '55555555', '66666666', '77777777', '12312312', '123123123', '321321321',
        '147258369', '159753258', '789456123', '741852963', '963852741', '14725836', '15975321', '25802580',
        '19901990', '19801980', '20002000', '20202020', '20212021', '20222022', '20232023', '20242024',
        '20252025', '20262026', '01710000', '01711111', '01712345', 'bangladesh', 'bangladesh1', 'dhaka123',
        'allah786', 'allahu786', 'bismillah', 'bismillah786', 'muhammad', 'madina123', 'madinanhc', 'mnhc1234',
        'mnhc12345', 'love1234', 'iloveyou1', 'pakistan', 'india123', '786786786', '78607860', '12345qwert',
        'qwertyuiop', 'zaq12wsx', 'aa123456', 'a1234567', 'a12345678', '1234qwer', 'q1w2e3r4', 'changeme',
    ];
}
