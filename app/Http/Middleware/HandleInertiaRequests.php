<?php

namespace App\Http\Middleware;

use App\Models\BusinessConfig;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [...parent::share($request),
            'auth' => ['user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'mfa_enabled' => (bool) $user->totp_secret] : null],
            'company' => fn () => BusinessConfig::find(1)?->only(['company_name', 'contact_email', 'contact_phone', 'mode', 'mode_revision']),
            'demo' => app()->environment('local') && config('dealer.demo_enabled'),
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
