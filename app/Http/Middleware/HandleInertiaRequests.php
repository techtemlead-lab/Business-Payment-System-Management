<?php
namespace App\Http\Middleware;
use Illuminate\Http\Request;
use Inertia\Middleware;
class HandleInertiaRequests extends Middleware {
    protected $rootView='app';
    public function share(Request $request): array {
        return [...parent::share($request),'auth'=>['user'=>$request->user()?->safe(),'capabilities'=>config('madina.roles.'.($request->user()?->role??''),[])],
            'portalBase'=>$request->user()?->staff()?'/company':'/workspace',
            'flash'=>['success'=>fn()=>$request->session()->get('success')],'financeActive'=>false,'withdrawalMinimum'=>config('madina.withdrawal_minimum'),'demoMode'=>app()->environment('local'),
            'staffRoles'=>$request->user()?->role==='super_admin'?array_keys(config('madina.roles')):[]];
    }
}
