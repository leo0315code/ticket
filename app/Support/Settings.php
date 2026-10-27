<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * 后台可配置项：数据库只存被改过的键，未配置的走 DEFAULTS。
 * 读取结果进程内缓存，写入后失效。
 */
class Settings
{
    public const DEFAULTS = [
        'site_name' => '工单支持中心',
        'allow_register' => true,
        'sla_hours' => ['urgent' => 4, 'high' => 8, 'medium' => 24, 'low' => 72],
        'auto_close_days' => 7,
        'reopen_days' => 14,
        'reopen_limit' => 3,
        'attach_max_mb' => 10,
        'notify_mail' => true,
    ];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = [];
            foreach (DB::table('settings')->get() as $row) {
                $stored[$row->key] = json_decode((string) $row->value, true);
            }
            self::$cache = array_replace(self::DEFAULTS, $stored);
        }

        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** @param array<string, mixed> $values */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
            );
        }

        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
