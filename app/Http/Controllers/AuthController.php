<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Totp;
use App\Services\WebsiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function login(Request $r): RedirectResponse
    {
        $r->merge(['email' => mb_strtolower(trim((string) $r->input('email')))]);
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($v)) {
            AuditEvent::record('auth.login_failed');
            throw ValidationException::withMessages(['email' => 'The supplied credentials do not match our records.']);
        }
        $r->session()->regenerate();
        if (Auth::user()->role === 'dealer' && Auth::user()->dealer?->status !== 'active') {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Your account is not active. Contact the company administrator.']);
        }
        AuditEvent::record('auth.login');

        $landing = Auth::user()->role === 'dealer' ? app(WebsiteService::class)->published()['portal']['landing'] : '/dashboard';

        return redirect(Auth::user()->totp_secret ? '/two-factor' : $landing);
    }

    public function demo(Request $r): RedirectResponse
    {
        abort_unless(app()->environment('local') && config('dealer.demo_enabled'), 404);
        $r->validate(['role' => 'required|in:admin,dealer']);
        $u = User::where('email', $r->role === 'admin' ? 'admin@example.test' : 'farhan@example.test')->firstOrFail();
        Auth::login($u);
        $r->session()->regenerate();

        return redirect('/dashboard');
    }

    public function logout(Request $r): RedirectResponse
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/');
    }

    public function forgot(Request $r): RedirectResponse
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return back()->with('success', 'If this account exists, a password reset link has been sent.');
    }

    public function reset(Request $r): RedirectResponse
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $u, string $password): void {
            $u->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $u->id)->delete();
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect('/login')->with('success', 'Your password has been reset.');
    }

    public function confirm(Request $r): RedirectResponse
    {
        $r->validate(['password' => 'required|string']);
        if (! Hash::check($r->password, $r->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
        $r->session()->put('auth.password_confirmed_at', time());

        return back()->with('success', 'Identity confirmed.');
    }

    public function security(Request $r, Totp $totp): Response|RedirectResponse
    {
        if ($r->user()->totp_secret && ! $r->session()->get('mfa_verified')) {
            return redirect('/two-factor');
        }

        return Inertia::render('Security', ['setupSecret' => $r->session()->get('totp_setup_secret'), 'recoveryCodes' => $r->session()->get('new_recovery_codes')]);
    }

    public function beginMfa(Request $r, Totp $totp): RedirectResponse
    {
        $this->confirm($r);
        abort_if((bool) $r->user()->totp_secret, 409);
        $r->session()->put('totp_setup_secret', $totp->secret());

        return back();
    }

    public function enableMfa(Request $r, Totp $totp): RedirectResponse
    {
        $r->validate(['code' => 'required|string|size:6']);
        $secret = $r->session()->get('totp_setup_secret');
        if (! $secret || ! $totp->verify($secret, $r->code)) {
            throw ValidationException::withMessages(['code' => 'The authenticator code is invalid.']);
        }
        $codes = array_map(fn () => bin2hex(random_bytes(8)), range(1, 8));
        $r->user()->forceFill(['totp_secret' => $secret, 'recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes), 'last_totp_step' => $totp->matchingStep($secret, $r->code)])->save();
        $r->session()->forget('totp_setup_secret');
        $r->session()->put('mfa_verified', true);
        $r->session()->flash('new_recovery_codes', $codes);
        AuditEvent::record('security.mfa_enabled');

        return back()->with('success', 'Two-factor authentication enabled. Save your recovery codes securely.');
    }

    public function verifyMfa(Request $r, Totp $totp): RedirectResponse
    {
        $r->validate(['code' => 'required|string|max:32']);
        $user = $r->user();
        abort_unless($user->totp_secret, 409);
        $ok = DB::transaction(function () use ($user, $r, $totp): bool {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $step = $totp->matchingStep($locked->totp_secret, $r->code);
            if ($step !== null && ($locked->last_totp_step === null || $step > $locked->last_totp_step)) {
                $locked->forceFill(['last_totp_step' => $step])->save();

                return true;
            }

            return false;
        });
        if (! $ok) {
            $ok = DB::transaction(function () use ($r, $user): bool {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $codes = $locked->recovery_codes ?? [];
                foreach ($codes as $i => $hash) {
                    if (Hash::check($r->code, $hash)) {
                        unset($codes[$i]);
                        $locked->forceFill(['recovery_codes' => array_values($codes)])->save();

                        return true;
                    }
                }

                return false;
            });
        }
        if (! $ok) {
            throw ValidationException::withMessages(['code' => 'The code is invalid or has expired.']);
        }
        $r->session()->regenerate();
        $r->session()->put('mfa_verified', true);

        return redirect('/dashboard');
    }
}
