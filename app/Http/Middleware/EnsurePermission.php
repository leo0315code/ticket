<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 细粒度权限校验：路由上写 permission:ticket.assign 这样 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission($permission), 403, '没有该操作权限');

        return $next($request);
    }
}
