<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 公开接口的可选登录：带了有效 Bearer token 就按该用户处理，
 * 没带或 token 无效则按游客放行（不抛 401）。
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            try {
                $user = Auth::guard('sanctum')->user();
                if ($user) {
                    $request->setUserResolver(fn () => $user);
                }
            } catch (\Throwable) {
                // token 过期/无效：按游客处理
            }
        }

        return $next($request);
    }
}
