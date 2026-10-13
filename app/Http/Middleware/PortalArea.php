<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class PortalArea
{
    public function handle(Request $request,Closure $next,string $area)
    {
        if($area==='company')abort_unless($request->user()->staff(),403);
        elseif($request->user()->staff()){
            $suffix=substr($request->getPathInfo(),strlen('/workspace'));
            return redirect('/company'.$suffix.($request->getQueryString()?'?'.$request->getQueryString():''));
        }
        return $next($request);
    }
}
