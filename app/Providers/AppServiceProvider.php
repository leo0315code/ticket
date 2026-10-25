<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function boot(): void
    {
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
