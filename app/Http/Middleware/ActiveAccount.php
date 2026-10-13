<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class ActiveAccount {
    public function handle(Request $request,Closure $next) {
        if ($request->user()?->status!=='active') { $login=$request->user()?->staff()?'/company/login':'/login';Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();if($request->header('X-Inertia'))return \Inertia\Inertia::location($login); return redirect($login)->withErrors(['email'=>'This account is unavailable. Contact the company.']); }
        return $next($request);
    }
}
