<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function boot(): void
    {
        // 角色权限直接映射成 Gate ability：$user->can('ticket.assign')
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ? true : null;
        });

        // 密码重置邮件里的链接指向前端页面（默认会指向 Laravel 自带的 password.reset 路由）
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $base = app()->runningInConsole()
                ? rtrim((string) config('app.url'), '/')
                : request()->getSchemeAndHttpHost();

            return $base.'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
        });

        // 登录防爆破：每 账号+IP 5 次/分钟
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('account', '').'|'.$request->ip());
        });
    }

    public function register(): void
    {
        //
    }

}
