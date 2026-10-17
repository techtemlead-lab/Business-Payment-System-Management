<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\PhoneIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(Request $r): Response
    {
        return $this->authenticate($r, 'partner');
    }

    public function companyLogin(Request $r): Response
    {
        return $this->authenticate($r, 'employee');
    }

    public function ownerLogin(Request $r): Response
    {
        return $this->authenticate($r, 'owner');
    }

    private function authenticate(Request $r, string $portal): Response
    {
        $data = $r->validate(['email' => 'required|string|max:254', 'password' => 'required|string|max:200'], [], ['email' => 'e-mail or phone number']);
        $identity = trim($data['email']);
        $isEmail = filter_var($identity, FILTER_VALIDATE_EMAIL) !== false;
        $phone = $isEmail ? null : PhoneIdentity::normalize($identity);
        $key = ($isEmail ? Str::lower($identity) : ($phone ?? Str::lower($identity))).'|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Please wait a minute.']);
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
        if (! $candidate || ($candidate->owner() ? 'owner' : ($candidate->staff() ? 'employee' : 'partner')) !== $portal || ! Auth::validate(['email' => $candidate->email, 'password' => $data['password'], 'status' => 'active'])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These credentials could not be verified.']);
        }
        Auth::login($candidate);
        RateLimiter::clear($key);
        $r->session()->regenerate();
        Audit::record($r->user()->id, 'login', 'account');
        $base = $candidate->staff() ? '/company' : '/workspace';
        $intended = $r->session()->pull('url.intended');
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;
        if (is_string($path) && ($path === $base || str_starts_with($path, $base.'/')) && parse_url($intended, PHP_URL_HOST) === $r->getHost()) {
            return redirect($intended);
        }

        return redirect($base);
    }

    public function logout(Request $r): Response
    {
        $login = $r->user()?->loginPath() ?? '/login';
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return $r->header('X-Inertia') ? Inertia::location($login) : redirect($login);
    }

    public function forgot(Request $r): Response
    {
        $data = $r->validate(['email' => 'required|email|max:254']);
        Password::sendResetLink($data);

        return back()->with('success', 'If the account is eligible, a password reset link will be sent.');
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

        return redirect(User::where('email', $data['email'])->first()?->loginPath() ?? '/login')->with('success', 'Password set. You can sign in now.');
    }

    public function password(Request $r): Response
    {
        $data = $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $r->user()->update(['password' => $data['password'], 'remember_token' => Str::random(60)]);
        DB::table('sessions')->where('user_id', $r->user()->id)->where('id', '!=', $r->session()->getId())->delete();
        $r->session()->regenerate();
        Audit::record($r->user()->id, 'password.change', 'account');

        return back()->with('success', 'Password updated. Other sessions have been revoked.');
    }
}
