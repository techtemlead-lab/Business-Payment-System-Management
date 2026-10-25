<?php

namespace App\Http\Controllers;

use App\Domain\Auth\LoginOtpChallenge;
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

        return $this->completeLogin($r, $candidate);
    }

    private function completeLogin(Request $r, User $candidate): Response
    {
        Auth::login($candidate);
        $r->session()->regenerate();
        Audit::record($r->user()->id, 'login', 'account');
        $intended = $r->session()->pull('url.intended');
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;
        if (is_string($intended) && is_string($path) && $path !== '/login' && $path !== '/login/otp' && parse_url($intended, PHP_URL_HOST) === $r->getHost()) {
            return redirect($intended);
        }

        return redirect('/');
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
            $u->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
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
        $r->user()->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $r->user()->id)->where('id', '!=', $r->session()->getId())->delete();
        $r->session()->regenerate();
        Audit::record($r->user()->id, 'password.change', 'account');

        return back()->with('success', __('portal.flash_password_updated'));
    }
}
