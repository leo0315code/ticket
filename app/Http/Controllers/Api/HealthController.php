<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 健康检查（给监控/负载均衡用，不暴露任何业务数据）
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function () {
                Cache::put('health:ping', '1', 10);
                abort_unless(Cache::get('health:ping') === '1', 500);
            }),
            'storage' => $this->check(fn () => abort_unless(is_writable(storage_path('app')), 500)),
            'queue' => $this->queueCheck(),
        ];

        $ok = ! in_array('fail', $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $ok ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            return 'fail';
        }
    }

    /** 队列走 database 驱动时，顺带报一下积压量；其他驱动返回 n/a */
    private function queueCheck(): string
    {
        try {
            if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
                return 'n/a';
            }

            return DB::table('jobs')->count() > 5000 ? 'warn' : 'ok';
        } catch (Throwable $e) {
            return 'n/a';
        }
    }
}
