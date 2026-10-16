<?php
namespace App\Http\Middleware;
use Illuminate\Http\Request;
use Inertia\Middleware;
class HandleInertiaRequests extends Middleware {
    protected $rootView='app';
    public function share(Request $request): array {
        $u = $request->user();
        $capabilities = $u ? (
            $u->role === 'super_admin'
                ? config('madina.roles.super_admin', [])
                : ($u->profile['permissions'] ?? config('madina.roles.'.$u->role, []))
        ) : [];
        return [...parent::share($request),'auth'=>['user'=>$u?->safe(),'capabilities'=>$capabilities],
            'portalBase'=>$u?->staff()?'/company':'/workspace',
            'flash'=>['success'=>fn()=>$request->session()->get('success')],'financeActive'=>false,'withdrawalMinimum'=>config('madina.withdrawal_minimum'),'demoMode'=>app()->environment('local'),
            'staffRoles'=>$u?->role==='super_admin'?['employee',...array_keys(config('madina.roles'))]:[],
            'dashboardNotice'=>\App\Support\DashboardManagement::noticeForUser($u),
            'dashboardControls'=>\App\Support\DashboardManagement::get()];
    }
}
