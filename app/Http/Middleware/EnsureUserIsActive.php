<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 停用账号：已登录的旧 token 也立刻失效 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->isActive()) {
            // 同时清掉当前 token，避免反复请求
            $user->currentAccessToken()?->delete();
            abort(403, '账号已停用，请联系管理员');
        }

        return $next($request);
    }
}
