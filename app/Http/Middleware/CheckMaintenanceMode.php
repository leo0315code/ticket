<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 维护模式：开启后普通用户/游客只读（GET/HEAD 放行），写操作一律 503；
 * 管理员不受影响；登录、验证码、meta 始终放行（否则管理员进不来、横幅取不到）。
 */
class CheckMaintenanceMode
{
    /** 维护期间也要放行的路径（无前缀匹配 api/ 之后的路径） */
    private const EXEMPT = [
        'meta',
        'login',
        'admin/login',
        'captcha/verify',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! Settings::get('maintenance_mode', false)) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $path = ltrim(preg_replace('#^api/#', '', $request->path()), '/');
        foreach (self::EXEMPT as $exempt) {
            if ($path === $exempt) {
                return $next($request);
            }
        }

        $user = $request->user();
        if ($user && $user->isAdmin()) {
            return $next($request);
        }

        return response()->json([
            'message' => Settings::get('maintenance_message') ?: '系统维护中，请稍后再试。',
        ], 503);
    }
}
