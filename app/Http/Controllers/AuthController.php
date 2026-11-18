<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountPhoneRules;
use App\Domain\Accounts\PartnerProfileLock;
use App\Domain\Auth\LoginCaptcha;
use App\Domain\Auth\LoginOtpChallenge;
use App\Domain\Auth\SignInGuard;
use App\Domain\Auth\TotpAuthenticator;
use App\Domain\Security\OwnerConfirmation;
use App\Domain\Security\SecuritySettings;
use App\Domain\Staff\LeadershipAccess;
use App\Http\Middleware\RequireAuthenticatorSetup;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\Audit;
use App\Support\LetterheadContacts;
use App\Support\PhoneIdentity;
use App\Support\PortalHost;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends Controller
{
    /** How long a sign-in may wait at the SMS code step before the person has to start again. */
    private const PendingLoginSeconds = 900;

    public function login(Request $r): Response
    {
        $portal = PortalHost::current($r);
        abort_unless(is_string($portal) && $portal !== 'public', 404);

        try {
            return $this->authenticate($r, $portal);
        } catch (ValidationException $exception) {
            // "Back" would be the captcha picture, the last page the browser loaded, so return to the form.
            throw $exception->redirectTo(url('/login'));
        }
    }

    /** A fresh captcha picture; every request replaces the previous one. */
    public function captcha(Request $r, LoginCaptcha $captcha): Response
    {
        $portal = PortalHost::current($r);
        abort_unless(is_string($portal) && $portal !== 'public', 404);
        abort_unless($this->captchaEnabled(), 404);
        abort_unless(function_exists('imagecreatetruecolor'), 503);

        return response($captcha->png($captcha->issue($r)), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function showOtp(Request $r, LoginOtpChallenge $otp): View|Response
    {
        $portal = PortalHost::current($r);
        abort_unless(is_string($portal) && $portal !== 'public', 404);
        $user = $this->pendingLoginUser($r, $portal);
        if (! $user) {
            return redirect('/login');
        }
        $data = [
            'mode' => 'otp',
            'phoneHint' => substr((string) preg_replace('/\D+/', '', (string) $user->phone), -3),
            'resendWait' => $otp->resendWait($user, $portal),
            'devCode' => $otp->showsCodeOnScreen() ? $r->session()->get('login_otp_dev_code') : null,
        ];

        return in_array($portal, ['manage', 'sadmin'], true)
            ? view('company.auth', [...$data, 'ownerPortal' => $portal === 'sadmin'])
            : view('reference.auth', [...$data, 'portal' => $portal]);
    }

    public function verifyOtp(Request $r, LoginOtpChallenge $otp, SecuritySettings $security): Response
    {
        $portal = PortalHost::current($r);
        abort_unless(is_string($portal) && $portal !== 'public', 404);
        $user = $this->pendingLoginUser($r, $portal);
        if (! $user) {
            $this->forgetPendingLogin($r);

            return redirect('/login')->withErrors(['email' => __('portal.flash_otp_sign_in_again')]);
        }
        $data = $r->validate(['code' => 'required|digits:6']);
        try {
            $otp->verify($user, $portal, $data['code']);
        } catch (ValidationException $exception) {
            $this->recordFailedLogin($r, $portal, 'otp_incorrect', $user);
            if (RateLimiter::tooManyAttempts('login-otp-fail|'.$user->id, LoginOtpChallenge::WrongCodesPerHour)) {
                $otp->forget($user, $portal);
                $this->forgetPendingLogin($r);
                Audit::record(null, 'login.otp_locked', (string) $user->id, ['portal' => $portal, 'ip' => $r->ip()]);
            }

            throw $exception;
        }
        $this->forgetPendingLogin($r);

        return $this->afterSecondFactor($r, $user, $portal, 'sms', $security);
    }

    public function resendOtp(Request $r, LoginOtpChallenge $otp): Response
    {
        $portal = PortalHost::current($r);
        abort_unless(is_string($portal) && $portal !== 'public', 404);
        $user = $this->pendingLoginUser($r, $portal);
        if (! $user) {
            $this->forgetPendingLogin($r);

            return redirect('/login')->withErrors(['email' => __('portal.flash_otp_sign_in_again')]);
        }
        $result = $otp->issue($user, $portal);
        if (! $result['sent']) {
            $message = match ($result['reason']) {
                'cooldown' => __('sms.err_otp_resend_wait', ['seconds' => (string) $result['retry_after']]),
                'limit' => __('sms.err_otp_send_limit', ['minutes' => (string) max(1, (int) ceil($result['retry_after'] / 60))]),
                default => __('sms.err_otp_send_failed'),
            };

            return redirect('/login/otp')->withErrors(['code' => $message]);
        }
        $this->rememberDevCode($r, $result['code']);
        Audit::record(null, 'login.otp_resent', (string) $user->id, ['portal' => $portal, 'ip' => $r->ip()]);

        return redirect('/login/otp')->with('success', __('portal.flash_otp_sent'));
    }

    public function showTotp(Request $r): View|Response
    {
        $portal = PortalHost::current($r);
        abort_unless(is_string($portal) && in_array($portal, ['manage', 'sadmin'], true), 404);
        if (! $r->session()->has('pending_totp_id')) {
            return redirect('/login');
        }

        return view('company.auth', [
            'mode' => 'totp',
            'ownerPortal' => $portal === 'sadmin',
        ]);
    }

    public function verifyTotp(Request $r, TotpAuthenticator $totp, SecuritySettings $security): Response
    {
        $portal = PortalHost::current($r);
        abort_unless(is_string($portal) && in_array($portal, ['manage', 'sadmin'], true), 404);
        if (! $security->totpChallengesEnabled()) {
            $r->session()->forget(['pending_totp_id', 'pending_totp_portal']);

            return redirect('/login');
        }
        $pendingId = $r->session()->get('pending_totp_id');
        if (! (is_int($pendingId) || (is_string($pendingId) && ctype_digit($pendingId)))) {
            return redirect('/login');
        }
        $data = $r->validate(['code' => 'required|string|max:20']);
        $user = User::query()->whereKey((int) $pendingId)->where('status', 'active')->first();
        if (! $user || PortalHost::forUser($user) !== $portal || ! $user->hasTotpEnabled()) {
            $r->session()->forget(['pending_totp_id', 'pending_totp_portal']);

            throw ValidationException::withMessages(['code' => __('portal.flash_otp_sign_in_again')]);
        }
        // Per-account guard: logging in again must not reset the guess budget.
        $attemptKey = 'totp-attempts:'.$user->id;
        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            $r->session()->forget(['pending_totp_id', 'pending_totp_portal']);
            Audit::record($user->id, 'security.totp_locked', 'account');

            throw ValidationException::withMessages(['code' => __('portal.err_totp_locked', [
                'minutes' => (string) max(1, (int) ceil(RateLimiter::availableIn($attemptKey) / 60)),
            ])]);
        }
        $code = trim($data['code']);
        $secret = (string) $user->totp_secret;
        if ($totp->verifyOnce($user, $secret, $code)) {
            RateLimiter::clear($attemptKey);

            return $this->completeLogin($r, $user, $this->totpMethod($r));
        }
        $recovery = is_array($user->totp_recovery_codes) ? $user->totp_recovery_codes : [];
        $normalized = Str::upper(str_replace(' ', '', $code));
        $matchIndex = null;
        foreach ($recovery as $index => $recoveryCode) {
            if (hash_equals(Str::upper(str_replace(' ', '', (string) $recoveryCode)), $normalized)) {
                $matchIndex = $index;
                break;
            }
        }
        if ($matchIndex === null) {
            RateLimiter::hit($attemptKey, 900);

            throw ValidationException::withMessages(['code' => __('portal.err_totp_invalid')]);
        }
        RateLimiter::clear($attemptKey);
        unset($recovery[$matchIndex]);
        $user->forceFill(['totp_recovery_codes' => array_values($recovery)])->save();
        Audit::record($user->id, 'security.totp_recovery_used', 'account');

        return $this->completeLogin($r, $user, $this->totpMethod($r));
    }

    /** The second-step record for the audit log once the authenticator code is also accepted. */
    private function totpMethod(Request $r): string
    {
        $method = $r->session()->pull('pending_login_method', 'none');
        $r->session()->forget(['pending_totp_id', 'pending_totp_portal']);

        return (is_string($method) ? $method : 'none').'+totp';
    }

    private function captchaEnabled(): bool
    {
        return app(SignInGuard::class)->captchaRequired(request());
    }

    private function authenticate(Request $r, string $portal): Response
    {
        $guard = app(SignInGuard::class);
        $captchaNeeded = $guard->captchaRequired($r);
        $rules = [
            'email' => 'required|string|max:254',
            'password' => 'required|string|max:200',
        ];
        if ($captchaNeeded) {
            $rules['captcha'] = 'required|string|max:20';
        }
        $data = $r->validate($rules, [], ['email' => __(match ($portal) {
            'subdealer' => 'auth_ui.email_phone_or_subdealer_code',
            'dealer' => 'auth_ui.email_phone_or_dealer_code',
            default => 'auth_ui.email_or_phone',
        }), 'captcha' => __('auth_ui.captcha_label')]);
        $identity = trim($data['email']);
        // The counters are keyed on the account found, so typing the same code or phone another way
        // ("MNHC 1001", "1001", "+880…") never starts a fresh count.
        $candidate = $this->findSignInAccount($identity);
        $key = $guard->accountAddressKey($candidate, $identity, $r);
        // A second, account-wide limit stops password guessing spread over many IP addresses.
        $accountKey = $guard->accountKey($candidate, $identity);
        $addressLocked = RateLimiter::tooManyAttempts($key, SignInGuard::AccountTriesPerAddress);
        $accountLocked = RateLimiter::tooManyAttempts($accountKey, SignInGuard::AccountTriesPerHour);
        if ($addressLocked || $accountLocked) {
            $seconds = max($addressLocked ? RateLimiter::availableIn($key) : 0, $accountLocked ? RateLimiter::availableIn($accountKey) : 0);
            $this->recordFailedLogin($r, $portal, 'locked', null, $identity);
            throw ValidationException::withMessages([
                'email' => __('portal.flash_too_many_attempts_seconds', ['seconds' => $seconds]),
            ]);
        }
        // The captcha is checked before the password, so a wrong picture answer says nothing about the password.
        if ($captchaNeeded) {
            $captchaResult = app(LoginCaptcha::class)->check($r, $data['captcha'] ?? null);
            if ($captchaResult !== 'ok') {
                RateLimiter::hit($key, 60);
                RateLimiter::hit($accountKey, 3600);
                $guard->recordAddressFailure($r);
                $this->recordFailedLogin($r, $portal, 'captcha_'.$captchaResult, null, $identity);
                throw ValidationException::withMessages([
                    'captcha' => $captchaResult === 'wrong' ? __('auth_ui.captcha_wrong') : __('auth_ui.captcha_expired'),
                ]);
            }
        }
        $rightPortal = $candidate !== null && PortalHost::forUser($candidate) === $portal;
        // Always run one password check, even when no account matched, so the answer time says nothing.
        $passwordOk = $guard->passwordMatches($rightPortal ? $candidate : null, $data['password']);
        if (! $passwordOk || $candidate?->status !== 'active') {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($accountKey, 3600);
            $guard->recordAddressFailure($r);
            if ($passwordOk && $candidate?->status === 'suspended') {
                // Only someone who knows the password learns the account is suspended.
                $this->recordFailedLogin($r, $portal, 'suspended', $candidate, $identity);
                $phone = $this->officePhone();
                throw ValidationException::withMessages(['email' => $phone !== ''
                    ? __('security.login_suspended', ['phone' => $phone])
                    : __('security.login_suspended_no_phone')]);
            }
            $this->recordFailedLogin($r, $portal, 'bad_credentials', $candidate, $identity);
            throw ValidationException::withMessages(['email' => __('portal.flash_bad_credentials')]);
        }
        RateLimiter::clear($key);
        RateLimiter::clear($accountKey);

        $otp = app(LoginOtpChallenge::class);
        $security = app(SecuritySettings::class);
        $decision = $otp->decide($candidate);
        if (in_array($decision, ['no_phone', 'unavailable'], true)) {
            // Every account needs its own mobile number, the Super Admin included (owner decision,
            // 2026-11-05). While SMS is not live, the owner's switch turns the code step off for
            // everyone; if that switch is on and SMS breaks, `php artisan madina:sms-login off`
            // on the server turns it off again.
            $this->recordFailedLogin($r, $portal, $decision, $candidate, $identity);
            throw ValidationException::withMessages([
                'email' => $decision === 'no_phone' ? __('auth_ui.otp_no_phone') : __('auth_ui.otp_sms_unavailable'),
            ]);
        }
        if ($decision === 'send') {
            $result = $otp->issue($candidate, $portal);
            if (! $result['sent'] && $result['reason'] !== 'cooldown') {
                $this->recordFailedLogin($r, $portal, 'sms_'.$result['reason'], $candidate, $identity);
                throw ValidationException::withMessages(['email' => $result['reason'] === 'limit'
                    ? __('sms.err_otp_send_limit', ['minutes' => (string) max(1, (int) ceil($result['retry_after'] / 60))])
                    : __('sms.err_otp_send_failed')]);
            }
            $r->session()->put([
                'pending_login_id' => $candidate->id,
                'pending_login_portal' => $portal,
                'pending_login_at' => now()->getTimestamp(),
            ]);
            $this->rememberDevCode($r, $result['code']);

            // During the resend cooldown the code sent moments ago is still valid, so just ask for it.
            return redirect('/login/otp')->with('success', __('portal.flash_otp_sent'));
        }

        return $this->afterSecondFactor($r, $candidate, $portal, $security->previewMode() ? 'off_trial_mode' : 'off_by_owner', $security);
    }

    /**
     * The one account a sign-in name points at: email, phone number or partner code. Active
     * accounts are looked up first; a suspended one is only found when no active account
     * matches, so a phone shared with a suspended account still signs in the active one.
     * More than one match returns nothing (a shared phone must never pick an account at random).
     */
    private function findSignInAccount(string $identity): ?User
    {
        $isEmail = filter_var($identity, FILTER_VALIDATE_EMAIL) !== false;
        $phone = $isEmail ? null : PhoneIdentity::normalize($identity);
        foreach (['active', 'suspended'] as $status) {
            $query = User::query()->where('status', $status);
            if ($isEmail) {
                $query->whereRaw('LOWER(email) = ?', [Str::lower($identity)]);
            } elseif ($phone) {
                $query->whereNotNull('phone')->whereRaw("phone ~ '^[0-9[:space:]()+.-]+$'")->whereRaw('('.PhoneIdentity::SQL.') = ?', [$phone]);
            } else {
                // Partner ID-card / dealer codes (e.g. MNHC1001, MNHC100001-000001). Cards printed
                // before the hyphen was dropped (MNHC-100001-000001) still sign in.
                $query->whereNotNull('dealer_code')->whereIn(DB::raw('UPPER(dealer_code)'), PlaceCode::serialForms($identity));
            }
            $matches = $query->limit(2)->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
            if ($matches->count() > 1) {
                return null;
            }
        }

        return null;
    }

    /**
     * A new password may not be one of the most-used PINs or the person's own phone number or
     * partner code. Digits-only passwords stay allowed (owner rule); there is no other strength rule.
     *
     * @throws ValidationException
     */
    private function refuseTooEasyPassword(string $password, ?User $account): void
    {
        if (app(SignInGuard::class)->isTooEasy($password, $account)) {
            throw ValidationException::withMessages(['password' => __('security.password_too_easy')]);
        }
    }

    /** The office mobile number from the website settings, for messages that say "call the office". */
    private function officePhone(): string
    {
        try {
            $lines = LetterheadContacts::lines();
        } catch (\Throwable) {
            return '';
        }

        return trim($lines['mobile'] !== '' ? $lines['mobile'] : $lines['whatsapp']);
    }

    /**
     * Staff who turned on an authenticator app are asked for its code as well. Owners and finance
     * staff who must have the app but have not set it up yet are signed in and sent straight to
     * the setup page; RequireAuthenticatorSetup keeps them there until the app is on. Everyone
     * else is signed in.
     */
    private function afterSecondFactor(Request $r, User $candidate, string $portal, string $method, SecuritySettings $security): Response
    {
        $staffPortal = in_array($portal, ['manage', 'sadmin'], true);
        if ($security->totpChallengesEnabled() && $staffPortal && $candidate->hasTotpEnabled()) {
            $r->session()->put([
                'pending_totp_id' => $candidate->id,
                'pending_totp_portal' => $portal,
                'pending_login_method' => $method,
            ]);

            return redirect('/login/2fa')->with('success', __('portal.flash_totp_required'));
        }
        if ($staffPortal && $security->totpSetupPending($candidate)) {
            $this->completeLogin($r, $candidate, $method);
            $r->session()->put(RequireAuthenticatorSetup::SessionKey, true);
            Audit::record($candidate->id, 'security.totp_setup_required', 'account', ['portal' => $portal]);

            return redirect(RequireAuthenticatorSetup::SetupPath)->with('success', __('portal.flash_totp_setup_required'));
        }

        return $this->completeLogin($r, $candidate, $method);
    }

    /** The account waiting for its SMS code in this browser, if that sign-in is still fresh. */
    private function pendingLoginUser(Request $r, string $portal): ?User
    {
        $pendingId = $r->session()->get('pending_login_id');
        $startedAt = (int) $r->session()->get('pending_login_at', 0);
        if (! (is_int($pendingId) || (is_string($pendingId) && ctype_digit($pendingId)))
            || $r->session()->get('pending_login_portal') !== $portal
            || $startedAt < now()->getTimestamp() - self::PendingLoginSeconds) {
            return null;
        }
        $user = User::query()->whereKey((int) $pendingId)->where('status', 'active')->first();

        return $user && PortalHost::forUser($user) === $portal ? $user : null;
    }

    private function forgetPendingLogin(Request $r): void
    {
        $r->session()->forget(['pending_login_id', 'pending_login_portal', 'pending_login_at', 'login_otp_dev_code']);
    }

    /** Developer machines only: keep the code in the session so the code page can show it. */
    private function rememberDevCode(Request $r, ?string $code): void
    {
        if ($code !== null) {
            $r->session()->put('login_otp_dev_code', $code);
        }
    }

    /**
     * Audit a failed sign-in. The typed username is masked, because people sometimes type
     * their password into the username box.
     */
    private function recordFailedLogin(Request $r, string $portal, string $reason, ?User $user, ?string $identity = null): void
    {
        $masked = null;
        if (is_string($identity) && $identity !== '') {
            $masked = mb_substr($identity, 0, 3).str_repeat('*', max(0, min(8, mb_strlen($identity) - 3)));
        }
        Audit::record(null, 'login.failed', $user ? (string) $user->id : 'unknown', array_filter([
            'portal' => $portal,
            'reason' => $reason,
            'username' => $user ? null : $masked,
            'ip' => $r->ip(),
        ], fn ($value) => $value !== null));
    }

    private function completeLogin(Request $r, User $candidate, string $method = 'sms'): Response
    {
        Auth::login($candidate);
        $r->session()->regenerate();
        $r->session()->put('session_fingerprint', hash('sha256', (string) $r->userAgent()));
        // SA-10: the session belongs to this portal, and an office session has a fixed end (BindSessionFingerprint).
        $r->session()->put(['session_portal' => PortalHost::current($r), 'session_started_at' => now()->getTimestamp()]);
        $this->persistLoginLocale($r);
        Audit::record($r->user()->id, 'login', 'account', [
            'portal' => PortalHost::current($r),
            'second_step' => $method,
            'ip' => $r->ip(),
        ]);
        $intended = $r->session()->pull('url.intended');
        // PP-10: partners always start on their home page; an old or shared link must not drop an older
        // partner on a page they did not expect. Office staff still go back to the page they asked for.
        if (in_array($candidate->role, ['dealer', 'sub_dealer'], true)) {
            return redirect('/');
        }
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;
        if (is_string($intended) && is_string($path) && $path !== '/login' && ! str_starts_with($path, '/login/') && parse_url($intended, PHP_URL_HOST) === $r->getHost() && $this->routeExists($intended)) {
            return redirect($intended);
        }

        return redirect('/');
    }

    /**
     * Only send people back to a page that exists on this portal; a stale or
     * prefetched URL must never land a freshly signed-in partner on a 404.
     */
    private function routeExists(string $url): bool
    {
        try {
            app('router')->getRoutes()->match(Request::create($url, 'GET'));

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    /** Keep the language chosen on the login screen after authentication. */
    private function persistLoginLocale(Request $r): void
    {
        $supported = config('madina.locales', ['en', 'bn']);
        $locale = $r->session()->get('locale') ?? $r->cookie('locale');
        if (! is_string($locale) || ! in_array($locale, $supported, true)) {
            return;
        }
        $user = $r->user();
        if (! $user || $user->locale === $locale) {
            return;
        }
        $user->forceFill(['locale' => $locale])->save();
        app()->setLocale($locale);
    }

    public function logout(Request $r): Response
    {
        $login = $r->user()?->loginPath() ?? PortalHost::loginUrl('dealer');
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return $r->header('X-Inertia') ? Inertia::location($login) : redirect($login);
    }

    public function forgot(Request $r): Response
    {
        $data = $r->validate(['email' => 'required|email|max:254']);
        Password::sendResetLink($data);

        return back()->with('success', __('portal.flash_reset_link_sent'));
    }

    public function reset(Request $r): Response
    {
        $data = $r->validate(['token' => 'required|string', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(8)]]);
        $status = Password::reset($data, function (User $u, string $password) {
            // Refused inside the callback, after the link is checked, so the link stays usable for a better try.
            $this->refuseTooEasyPassword($password, $u);
            $u->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'password_managed_by' => User::PasswordManagedByAdmin,
                'admin_password_cipher' => $u->recoverablePasswordCopy($password),
            ])->save();
            DB::table('sessions')->where('user_id', $u->id)->delete();
            Audit::record($u->id, 'password.reset', 'account');
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect(User::where('email', $data['email'])->first()?->loginPath() ?? PortalHost::url(PortalHost::current() ?? 'dealer', '/login'))->with('success', __('portal.flash_password_set'));
    }

    public function ownerContact(Request $request, OwnerConfirmation $confirmation): Response
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $confirmation->require($request, $owner);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($owner->id)],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
        ]);
        if (array_key_exists('phone', $data)) {
            $phones = app(AccountPhoneRules::class);
            $data['phone'] = $phones->normalize($data['phone']);
            if (PhoneIdentity::normalize($data['phone']) !== PhoneIdentity::normalize((string) $owner->phone) && $phones->isTaken($data['phone'], $owner->id)) {
                throw ValidationException::withMessages(['phone' => __('portal.acct_val_phone_taken')]);
            }
        }
        DB::transaction(function () use ($owner, $data, $request): void {
            $previousEmail = $owner->email;
            $changed = [];
            $updates = [];

            if (array_key_exists('name', $data) && $data['name'] !== $owner->name) {
                $updates['name'] = $data['name'];
                $changed[] = 'name';
            }
            if (array_key_exists('phone', $data) && ($data['phone'] ?: null) !== ($owner->phone ?: null)) {
                $updates['phone'] = filled($data['phone'] ?? null) ? $data['phone'] : null;
                $changed[] = 'phone';
            }
            if ($previousEmail !== $data['email']) {
                $updates['email'] = $data['email'];
                $updates['email_verified_at'] = null;
                $updates['remember_token'] = Str::random(60);
                $changed[] = 'email';
            }

            if ($changed === []) {
                return;
            }

            $owner->forceFill($updates)->save();

            if (in_array('email', $changed, true)) {
                DB::table('password_reset_tokens')->whereIn('email', [$previousEmail, $data['email']])->delete();
                DB::table('sessions')->where('user_id', $owner->id)->where('id', '!=', $request->session()->getId())->delete();
            }

            Audit::record($owner->id, 'owner.contact_updated', (string) $owner->id, ['changed_fields' => $changed]);
        });
        $request->session()->regenerate();

        return back()->with('success', __('portal.flash_owner_profile_updated'));
    }

    public function profilePhoto(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->mayReplaceWorkspacePhoto($user), 403);

        $data = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
        ]);
        /** @var UploadedFile $file */
        $file = $data['photo'];
        $extension = strtolower((string) ($file->guessExtension() ?: 'jpg'));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $relative = $file->storeAs('staff-photos/'.$user->id, (string) Str::uuid().'.'.$extension, 'public');
        abort_unless(is_string($relative), 500);
        $url = $this->publicPhotoPath($relative);
        $profile = is_array($user->profile) ? $user->profile : [];
        $previous = $profile['photo_url'] ?? null;
        $profile['photo_url'] = $url;
        $profile['avatar_url'] = $url;
        $user->forceFill(['profile' => $profile])->save();
        $this->forgetPreviousStaffPhoto($user, $previous);
        Audit::record($user->id, 'profile.photo_updated', (string) $user->id);

        return $this->previewAwareBack($request)->with('success', __('portal.flash_profile_photo_updated'));
    }

    private function publicPhotoPath(string $relative): string
    {
        $absolute = Storage::disk('public')->url($relative);
        $path = parse_url($absolute, PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, '/')
            ? $path
            : '/storage/'.ltrim($relative, '/');
    }

    private function previewAwareBack(Request $request): RedirectResponse
    {
        $root = PortalHost::sharedPreviewRoot($request);
        if (! is_string($root) || $root === '') {
            return back();
        }

        $previous = url()->previous();
        $path = parse_url($previous, PHP_URL_PATH);
        if (! is_string($path) || $path === '' || str_starts_with($path, '/commands/')) {
            $path = '/';
        }
        $query = parse_url(is_string($previous) ? $previous : '', PHP_URL_QUERY);

        return redirect($root.$path.($query ? '?'.$query : ''));
    }

    private function mayReplaceWorkspacePhoto(User $user): bool
    {
        if (in_array($user->role, ['dealer', 'sub_dealer'], true)) {
            return $user->usingStaffWorkspace() || PartnerProfileLock::partnerMayEdit($user);
        }

        return $user->owner() || $user->staff();
    }

    private function forgetPreviousStaffPhoto(User $user, mixed $previous): void
    {
        if (! is_string($previous) || $previous === '') {
            return;
        }

        $path = parse_url($previous, PHP_URL_PATH);
        $prefix = '/storage/staff-photos/'.$user->id.'/';
        if (! is_string($path) || ! str_starts_with($path, $prefix)) {
            return;
        }

        $relative = ltrim(substr($path, strlen('/storage/')), '/');
        if (! str_starts_with($relative, 'staff-photos/'.$user->id.'/')) {
            return;
        }

        Storage::disk('public')->delete($relative);
    }

    public function password(Request $r): Response
    {
        $data = $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', PasswordRule::min(8)]]);
        $this->refuseTooEasyPassword($data['password'], $r->user());
        $r->user()->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
            'password_managed_by' => User::PasswordManagedByAdmin,
            'admin_password_cipher' => $r->user()->recoverablePasswordCopy($data['password']),
        ])->save();
        DB::table('sessions')->where('user_id', $r->user()->id)->where('id', '!=', $r->session()->getId())->delete();
        $r->session()->regenerate(true);
        $r->session()->put('session_fingerprint', hash('sha256', (string) $r->userAgent()));
        Audit::record($r->user()->id, 'password.change', 'account');

        return back()->with('success', __('portal.flash_password_updated'));
    }

    public function partnerLoginOtp(Request $request, SecuritySettings $security, OwnerConfirmation $confirmation): Response
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $confirmation->require($request, $owner);
        $data = $request->validate([
            'enabled' => 'required|in:0,1',
            'version' => 'required|integer|min:0',
        ]);
        $security->savePartnerLoginOtp($owner, $data['enabled'] === '1', (int) $data['version']);

        return back()->with('success', __('portal.flash_partner_login_otp_updated'));
    }

    /** Owners only: choose how a sensitive change is confirmed (nothing, the password, or a code sent to the owner's phone). */
    public function ownerConfirmation(Request $request, OwnerConfirmation $confirmation): Response
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $confirmation->require($request, $owner);
        $data = $request->validate([
            'mode' => 'required|in:'.implode(',', OwnerConfirmation::Modes),
            'version' => 'required|integer|min:0',
            'accept_no_sms' => 'sometimes|in:0,1',
        ]);
        $confirmation->save($owner, $data['mode'], (int) $data['version'], ($data['accept_no_sms'] ?? '0') === '1');

        return back()->with('success', __('owner_security.flash_saved'));
    }

    /** Owners only: send a one-time confirmation code to the owner's own phone. */
    public function ownerConfirmationCode(Request $request, OwnerConfirmation $confirmation): Response
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $result = $confirmation->sendCode($owner);
        if ($result['sent']) {
            return back()->with('success', __('owner_security.flash_code_sent'))->with('owner_code_on_screen', $result['code']);
        }
        $message = match ($result['reason']) {
            'cooldown' => __('sms.err_otp_resend_wait', ['seconds' => (string) $result['retry_after']]),
            'limit' => __('owner_security.err_code_limit'),
            default => __('owner_security.err_code_not_sent'),
        };

        return back()->withErrors(['confirm_code' => $message]);
    }

    /** Owners only: switch the "staff reset a password" notice on or off. */
    public function passwordResetNotice(Request $request, SecuritySettings $security, OwnerConfirmation $confirmation): Response
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $confirmation->require($request, $owner);
        $data = $request->validate([
            'enabled' => 'required|in:0,1',
            'version' => 'required|integer|min:0',
        ]);
        $security->savePasswordResetNotice($owner, $data['enabled'] === '1', (int) $data['version']);

        return back()->with('success', __('portal.flash_password_reset_notice_updated'));
    }

    /** Owners only: switch "one mobile number, one account" on or off (off by default). */
    public function oneAccountPerIdentity(Request $request, SecuritySettings $security, OwnerConfirmation $confirmation): Response
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $confirmation->require($request, $owner);
        $data = $request->validate([
            'enabled' => 'required|in:0,1',
            'version' => 'required|integer|min:0',
        ]);
        $security->saveOneAccountPerIdentity($owner, $data['enabled'] === '1', (int) $data['version']);

        return back()->with('success', __('portal.flash_one_account_per_identity_updated'));
    }

    /** LC-07 (D13): whether dealers see the full numbers of members they added directly. */
    public function teamPhones(Request $request, SecuritySettings $security, OwnerConfirmation $confirmation): Response
    {
        $owner = $request->user();
        abort_unless($owner instanceof User && LeadershipAccess::granted($owner, 'owner_tools'), 403);
        $confirmation->require($request, $owner);
        $data = $request->validate([
            'enabled' => 'required|in:0,1',
            'version' => 'required|integer|min:0',
        ]);
        $security->saveTeamPhones($owner, $data['enabled'] === '1', (int) $data['version']);

        return back()->with('success', __('owner_security.flash_team_phones_saved'));
    }

    /** SA-07 (D11): the Super Admin chooses which owner areas the MD and the Chairman may open. */
    public function leadershipAccess(Request $request, OwnerConfirmation $confirmation): Response
    {
        $owner = $request->user();
        abort_unless($owner instanceof User && $owner->role === 'super_admin', 403);
        $confirmation->require($request, $owner);
        $rules = ['version' => 'required|integer|min:0'];
        foreach (LeadershipAccess::Roles as $role) {
            foreach (LeadershipAccess::Areas as $area) {
                $rules[$role.'_'.$area] = 'required|in:0,1';
            }
        }
        $data = $request->validate($rules);
        $byRole = [];
        foreach (LeadershipAccess::Roles as $role) {
            $byRole[$role] = array_values(array_filter(LeadershipAccess::Areas, fn (string $area): bool => $data[$role.'_'.$area] === '1'));
        }
        LeadershipAccess::save($owner, $byRole, (int) $data['version']);

        return back()->with('success', __('owner_security.flash_leadership_saved'));
    }

    public function beginTotp(Request $request, TotpAuthenticator $totp, SecuritySettings $security): Response
    {
        $user = $request->user();
        abort_unless($user && ($user->staff() || $user->owner()), 403);
        abort_unless($security->totpChallengesEnabled(), 422, __('portal.err_totp_preview_disabled'));
        $request->validate(['current_password' => 'required|current_password']);
        $secret = $totp->generateSecret();
        $request->session()->put('totp_setup_secret', $secret);

        return back()->with([
            'success' => __('portal.flash_totp_setup_started'),
            'totp_setup' => [
                'secret' => $secret,
                'uri' => $totp->provisioningUri($user, $secret),
            ],
        ]);
    }

    public function confirmTotp(Request $request, TotpAuthenticator $totp, SecuritySettings $security): Response
    {
        $user = $request->user();
        abort_unless($user && ($user->staff() || $user->owner()), 403);
        abort_unless($security->totpChallengesEnabled(), 422, __('portal.err_totp_preview_disabled'));
        $data = $request->validate([
            'current_password' => 'required|current_password',
            'code' => 'required|digits:6',
        ]);
        $secret = $request->session()->get('totp_setup_secret');
        if (! is_string($secret) || $secret === '') {
            throw ValidationException::withMessages(['code' => __('portal.err_totp_setup_expired')]);
        }
        $totp->assertValid($secret, $data['code']);
        $recovery = $totp->recoveryCodes();
        $user->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'totp_recovery_codes' => $recovery,
        ])->save();
        $request->session()->forget('totp_setup_secret');
        Audit::record($user->id, 'security.totp_enabled', 'account');
        $this->tellAboutNewAuthenticator($user);

        return back()->with([
            'success' => __('portal.flash_totp_enabled'),
            'totp_recovery_codes' => $recovery,
        ]);
    }

    /**
     * SA-10: whoever holds a password could enrol their own authenticator at the first set-up step.
     * The account holder and the Super Admin are told at once, so a stranger's set-up is noticed.
     */
    private function tellAboutNewAuthenticator(User $user): void
    {
        $time = now()->timezone('Asia/Dhaka')->format('d M Y, h:i A');
        $recipients = User::query()->where('role', 'super_admin')->where('status', 'active')->pluck('id')->push($user->id)->unique();
        foreach (User::query()->whereKey($recipients->all())->get(['id', 'locale']) as $recipient) {
            $locale = $recipient->preferredLocale();
            Audit::notice(
                $recipient->id,
                __('security.notice_totp_enrolled_title', [], $locale),
                __('security.notice_totp_enrolled_body', ['name' => $user->name, 'time' => $time], $locale),
            );
        }
    }

    public function disableTotp(Request $request, SecuritySettings $security): Response
    {
        $user = $request->user();
        abort_unless($user && ($user->staff() || $user->owner()), 403);
        $data = $request->validate([
            'current_password' => 'required|current_password',
            'code' => 'nullable|string|max:20',
        ]);
        if ($user->hasTotpEnabled() && $security->totpChallengesEnabled()) {
            $code = trim((string) ($data['code'] ?? ''));
            abort_unless($code !== '', 422);
            $totp = app(TotpAuthenticator::class);
            if (! $totp->verifyOnce($user, (string) $user->totp_secret, $code)) {
                throw ValidationException::withMessages(['code' => __('portal.err_totp_invalid')]);
            }
        }
        $user->forceFill([
            'totp_secret' => null,
            'totp_confirmed_at' => null,
            'totp_recovery_codes' => null,
        ])->save();
        $request->session()->forget('totp_setup_secret');
        Audit::record($user->id, 'security.totp_disabled', 'account');

        return back()->with('success', __('portal.flash_totp_disabled'));
    }

    public function revokeOtherSessions(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user, 403);
        $request->validate(['current_password' => 'required|current_password']);
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->regenerate();
        $request->session()->put('session_fingerprint', hash('sha256', (string) $request->userAgent()));
        Audit::record($user->id, 'security.sessions_revoked', 'account');

        return back()->with('success', __('portal.flash_sessions_revoked'));
    }

    public function privacyExport(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user, 403);
        $request->validate(['current_password' => 'required|current_password']);
        $payload = [
            'exported_at' => now()->toIso8601String(),
            'account' => $user->safe(),
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'dealer_code' => $user->dealer_code,
            'division' => $user->division,
            'district' => $user->district,
            'profile' => collect(is_array($user->profile) ? $user->profile : [])
                ->except(['nid', 'birth_certificate_number', 'admin_notes'])
                ->all(),
            'totp_enabled' => $user->hasTotpEnabled(),
        ];
        Audit::record($user->id, 'privacy.subject_access_export', 'account');

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="madina-privacy-export-'.$user->id.'.json"',
        ]);
    }
}
