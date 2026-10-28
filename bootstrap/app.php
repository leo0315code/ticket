<?php

use Illuminate\Foundation\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\TooManyRequestsHttpException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API 未认证时直接抛 AuthenticationException（由异常渲染返回 401 JSON），不重定向
        $middleware->redirectGuestsTo(fn () => null);

        // 反向代理/负载均衡后取真实协议与 IP（nginx 反代时配 TRUSTED_PROXIES=127.0.0.1 或 *）
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // API 请求里记录用户活跃时间（在线状态）
        $middleware->appendToGroup('api', \App\Http\Middleware\TrackPresence::class);
        // 维护模式：开启后非管理员只读
        $middleware->appendToGroup('api', \App\Http\Middleware\CheckMaintenanceMode::class);

        $middleware->alias([
            'staff' => \App\Http\Middleware\EnsureUserIsStaff::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'auth.optional' => \App\Http\Middleware\OptionalSanctumAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => '未登录或登录已过期'], 401);
            }
        });

        // 权限/限流也统一中文，避免把框架英文提示露给用户
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => '没有该操作权限'], 403);
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => '操作太频繁，请稍后再试'], 429);
            }
        });
    })->create();
