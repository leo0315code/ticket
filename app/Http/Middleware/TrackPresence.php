<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 记录登录用户活跃时间：60 秒内只写一次库，避免每个请求都 UPDATE。
 * 「在线」= last_seen_at 在 User::ONLINE_MINUTES 分钟内。
 */
class TrackPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user && (! $user->last_seen_at || $user->last_seen_at->diffInSeconds() > 60)) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $response;
    }
}
