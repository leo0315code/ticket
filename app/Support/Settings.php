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
        'resolution_hours' => ['urgent' => 24, 'high' => 48, 'medium' => 72, 'low' => 120],
        'business_hours_enabled' => false,
        'business_hours_start' => '09:00',
        'business_hours_end' => '18:00',
        'business_hours_days' => [1, 2, 3, 4, 5],
        'business_timezone' => 'Asia/Shanghai',
        'auto_close_days' => 7,
        'reopen_days' => 14,
        'reopen_limit' => 3,
        'attach_max_mb' => 10,
        'attach_max_count' => 10,
        'attach_quota_mb' => 0,     // 单工单附件总容量上限，0 = 不限
        'notify_mail' => true,

        // 登录方式：手机号验证码 / 微信公众号扫码
        'phone_login_enabled' => false,
        'sms_provider' => 'log',
        'sms_driver' => 'log',
        'sms_aliyun_key' => '',
        'sms_aliyun_secret' => '',
        'sms_aliyun_sign' => '',
        'sms_aliyun_template' => '',
        'sms_tencent_secret_id' => '',
        'sms_tencent_secret_key' => '',
        'sms_tencent_app_id' => '',
        'sms_tencent_sign' => '',
        'sms_tencent_template' => '',
        'sms_tencent_region' => 'ap-guangzhou',
        'sms_yunpian_key' => '',          // log（写日志，便于联调）| http（POST 到自己的短信网关）
        'sms_http_url' => '',
        'sms_sign' => '工单支持中心',
        'sms_code_ttl' => 300,          // 验证码有效期（秒）
        'wechat_login_enabled' => false,
        'wechat_mode' => 'open',        // open（开放平台网站应用扫码）| mp（公众号网页授权）
        'wechat_app_id' => '',
        'wechat_app_secret' => '',
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
