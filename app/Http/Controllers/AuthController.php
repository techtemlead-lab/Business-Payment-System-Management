<?php

namespace App\Http\Controllers;

use App\Domain\Auth\LoginOtpChallenge;
use App\Domain\Auth\TotpAuthenticator;
use App\Domain\Security\SecuritySettings;
use App\Models\User;
use App\Support\Audit;
use App\Support\PhoneIdentity;
use App\Support\PortalHost;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(Request $r): Response
    {
        $portal = PortalHost::current($r);
        abort_unless(is_string($portal) && $portal !== 'public', 404);

        return $this->authenticate($r, $portal);
    }

    public function showOtp(Request $r): View|Response
    {
        $portal = PortalHost::current($r);
        abort_unless(is_string($portal) && in_array($portal, ['dealer', 'subdealer'], true), 404);
        if (! $r->session()->has('pending_login_id')) {
            return redirect('/login');
        }

        return view('reference.auth', ['mode' => 'otp', 'portal' => $portal]);
    }

    public function verifyOtp(Request $r, LoginOtpChallenge $otp): Response
    {
        $portal = PortalHost::current($r);
        abort_unless(is_string($portal) && in_array($portal, ['dealer', 'subdealer'], true), 404);
        $pendingId = $r->session()->get('pending_login_id');
        if (! (is_int($pendingId) || (is_string($pendingId) && ctype_digit($pendingId)))) {
            return redirect('/login');
        }
        $data = $r->validate(['code' => 'required|digits:6']);
        $user = User::query()->whereKey((int) $pendingId)->where('status', 'active')->first();
        if (! $user || PortalHost::forUser($user) !== $portal) {
            $r->session()->forget(['pending_login_id', 'pending_login_portal']);

            throw ValidationException::withMessages(['code' => __('portal.flash_otp_sign_in_again')]);
        }
        $otp->verify($user, $portal, $data['code']);
        $r->session()->forget(['pending_login_id', 'pending_login_portal']);

        return $this->completeLogin($r, $user);
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
        $code = trim($data['code']);
        $secret = (string) $user->totp_secret;
        if ($totp->verify($secret, $code)) {
            $r->session()->forget(['pending_totp_id', 'pending_totp_portal']);

            return $this->completeLogin($r, $user);
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
            throw ValidationException::withMessages(['code' => __('portal.err_totp_invalid')]);
        }
        unset($recovery[$matchIndex]);
        $user->forceFill(['totp_recovery_codes' => array_values($recovery)])->save();
        $r->session()->forget(['pending_totp_id', 'pending_totp_portal']);
        Audit::record($user->id, 'security.totp_recovery_used', 'account');

        return $this->completeLogin($r, $user);
    }

    private function authenticate(Request $r, string $portal): Response
    {
        $data = $r->validate(['email' => 'required|string|max:254', 'password' => 'required|string|max:200'], [], ['email' => 'e-mail or phone number']);
        $identity = trim($data['email']);
        $isEmail = filter_var($identity, FILTER_VALIDATE_EMAIL) !== false;
        $phone = $isEmail ? null : PhoneIdentity::normalize($identity);
        $key = ($isEmail ? Str::lower($identity) : ($phone ?? Str::lower($identity))).'|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => __('portal.flash_too_many_attempts')]);
        }
        $query = User::where('status', 'active');
        if ($isEmail) {
            $query->whereRaw('LOWER(email) = ?', [Str::lower($identity)]);
        } elseif ($phone) {
            $query->whereNotNull('phone')->whereRaw("phone ~ '^[0-9[:space:]()+.-]+$'")->whereRaw('('.PhoneIdentity::SQL.') = ?', [$phone]);
        } else {
            $query->whereRaw('1=0');
        }
        // Shared phone contacts must never select an arbitrary account. Email
        // remains available if more than one active identity matches a phone.
        $matches = $query->limit(2)->get();
        $candidate = $matches->count() === 1 ? $matches->first() : null;
        if (! $candidate || PortalHost::forUser($candidate) !== $portal || ! Auth::validate(['email' => $candidate->email, 'password' => $data['password'], 'status' => 'active'])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => __('portal.flash_bad_credentials')]);
        }
        RateLimiter::clear($key);

        $otp = app(LoginOtpChallenge::class);
        if ($otp->requiredFor($candidate)) {
            $otp->issue($candidate, $portal);
            $r->session()->put([
                'pending_login_id' => $candidate->id,
                'pending_login_portal' => $portal,
            ]);

            return redirect('/login/otp')->with('success', __('portal.flash_otp_sent'));
        }

        $security = app(SecuritySettings::class);
        if (
            $security->totpChallengesEnabled()
            && in_array($portal, ['manage', 'sadmin'], true)
            && $candidate->hasTotpEnabled()
        ) {
            $r->session()->put([
                'pending_totp_id' => $candidate->id,
                'pending_totp_portal' => $portal,
            ]);

            return redirect('/login/2fa')->with('success', __('portal.flash_totp_required'));
        }

        return $this->completeLogin($r, $candidate);
    }

    private function completeLogin(Request $r, User $candidate): Response
    {
        Auth::login($candidate);
        $r->session()->regenerate();
        $r->session()->put('session_fingerprint', hash('sha256', (string) $r->userAgent()));
        $this->persistLoginLocale($r);
        Audit::record($r->user()->id, 'login', 'account');
        $intended = $r->session()->pull('url.intended');
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;
        if (is_string($intended) && is_string($path) && ! in_array($path, ['/login', '/login/otp', '/login/2fa'], true) && parse_url($intended, PHP_URL_HOST) === $r->getHost()) {
            return redirect($intended);
        }

        return redirect('/');
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
        $data = $r->validate(['token' => 'required|string', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $status = Password::reset($data, function (User $u, string $password) {
            $u->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'password_managed_by' => User::PasswordManagedByPartner,
                'admin_password_cipher' => null,
            ])->save();
            DB::table('sessions')->where('user_id', $u->id)->delete();
            Audit::record($u->id, 'password.reset', 'account');
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect(User::where('email', $data['email'])->first()?->loginPath() ?? PortalHost::url(PortalHost::current() ?? 'dealer', '/login'))->with('success', __('portal.flash_password_set'));
    }

    public function ownerContact(Request $request): Response
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $data = $request->validate([
            'current_password' => 'required|current_password',
            'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($owner->id)],
        ]);
        DB::transaction(function () use ($owner, $data, $request): void {
            $previousEmail = $owner->email;
            if ($previousEmail !== $data['email']) {
                $owner->forceFill(['email' => $data['email'], 'email_verified_at' => null, 'remember_token' => Str::random(60)])->save();
                DB::table('password_reset_tokens')->whereIn('email', [$previousEmail, $data['email']])->delete();
                DB::table('sessions')->where('user_id', $owner->id)->where('id', '!=', $request->session()->getId())->delete();
                Audit::record($owner->id, 'owner.contact_updated', (string) $owner->id, ['changed_fields' => ['email']]);
            }
        });
        $request->session()->regenerate();

        return back()->with('success', __('portal.flash_owner_email_updated'));
    }

    public function password(Request $r): Response
    {
        $data = $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $r->user()->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
            'password_managed_by' => User::PasswordManagedByPartner,
            'admin_password_cipher' => null,
        ])->save();
        DB::table('sessions')->where('user_id', $r->user()->id)->where('id', '!=', $r->session()->getId())->delete();
        $r->session()->regenerate();
        $r->session()->put('session_fingerprint', hash('sha256', (string) $r->userAgent()));
        Audit::record($r->user()->id, 'password.change', 'account');

        return back()->with('success', __('portal.flash_password_updated'));
    }

    public function partnerLoginOtp(Request $request, SecuritySettings $security): Response
    {
        $owner = $request->user();
        abort_unless($owner?->owner(), 403);
        $data = $request->validate([
            'enabled' => 'required|in:0,1',
            'version' => 'required|integer|min:0',
            'current_password' => 'required|current_password',
        ]);
        $security->savePartnerLoginOtp($owner, $data['enabled'] === '1', (int) $data['version']);

        return back()->with('success', __('portal.flash_partner_login_otp_updated'));
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

        return back()->with([
            'success' => __('portal.flash_totp_enabled'),
            'totp_recovery_codes' => $recovery,
        ]);
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
            if (! $totp->verify((string) $user->totp_secret, $code)) {
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
