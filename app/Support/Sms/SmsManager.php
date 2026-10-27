<?php

namespace App\Support\Sms;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * 验证码：缓存里存哈希与尝试次数，5 次失败即作废。
 * 发送节流：同手机号 60 秒一次、每天最多 10 条。
 */
class SmsManager
{
    public const MAX_ATTEMPTS = 5;

    public static function sender(): SmsSender
    {
        // 测试可绑定假实现，避免真的发短信
        if (app()->bound(SmsSender::class)) {
            return app(SmsSender::class);
        }

        $provider = self::provider();
        $sign = (string) Settings::get('sms_sign', '工单支持中心');

        return match ($provider) {
            'aliyun' => new AliyunSmsSender(
                (string) Settings::get('sms_aliyun_key'),
                (string) Settings::get('sms_aliyun_secret'),
                (string) Settings::get('sms_aliyun_sign'),
                (string) Settings::get('sms_aliyun_template'),
            ),
            'tencent' => new TencentSmsSender(
                (string) Settings::get('sms_tencent_secret_id'),
                (string) Settings::get('sms_tencent_secret_key'),
                (string) Settings::get('sms_tencent_app_id'),
                (string) Settings::get('sms_tencent_sign'),
                (string) Settings::get('sms_tencent_template'),
                (string) Settings::get('sms_tencent_region', 'ap-guangzhou'),
            ),
            'yunpian' => new YunpianSmsSender((string) Settings::get('sms_yunpian_key'), $sign),
            'http' => new HttpSmsSender((string) Settings::get('sms_http_url'), $sign),
            default => new LogSmsSender(),
        };
    }

    /** 当前短信平台：log / aliyun / tencent / yunpian / http */
    public static function provider(): string
    {
        $provider = (string) Settings::get('sms_provider', 'log');
        // 兼容旧配置：只设过 sms_driver=http
        if ($provider === 'log' && Settings::get('sms_driver') === 'http') {
            return 'http';
        }

        return in_array($provider, ['log', 'aliyun', 'tencent', 'yunpian', 'http'], true) ? $provider : 'log';
    }

    /** 后台“发送测试短信”，直接用当前平台发一条示例码 */
    public static function sendTest(string $phone): void
    {
        self::sender()->send($phone, '123456', 'test');
    }

    /** 生成并发送验证码；返回有效期（秒） */
    public static function sendCode(string $phone, string $purpose): int
    {
        $ttl = max(60, (int) Settings::get('sms_code_ttl', 300));

        if (! Cache::add(self::key($phone, $purpose, 'cooldown'), 1, 60)) {
            throw new \RuntimeException('发送太频繁，请 60 秒后再试');
        }

        $dailyKey = self::key($phone, $purpose, 'daily');
        $sentToday = (int) Cache::get($dailyKey, 0);
        if ($sentToday >= 10) {
            throw new \RuntimeException('该手机号今日验证码次数已达上限');
        }
        Cache::put($dailyKey, $sentToday + 1, now()->endOfDay());

        $code = (string) random_int(100000, 999999);

        Cache::put(self::key($phone, $purpose), [
            'hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds($ttl)->getTimestamp(),
        ], $ttl);

        self::sender()->send($phone, $code, $purpose);

        return $ttl;
    }

    public static function verify(string $phone, string $purpose, string $code): bool
    {
        $key = self::key($phone, $purpose);
        $data = Cache::get($key);
        if (! $data) {
            return false;
        }

        if (($data['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return false;
        }

        if (! Hash::check($code, $data['hash'])) {
            $data['attempts'] = (int) ($data['attempts'] ?? 0) + 1;
            $remaining = max(1, (int) ($data['expires_at'] ?? now()->timestamp) - now()->timestamp);
            Cache::put($key, $data, $remaining);

            return false;
        }

        Cache::forget($key);
        Cache::forget(self::key($phone, $purpose, 'cooldown'));

        return true;
    }

    private static function key(string $phone, string $purpose, string $suffix = 'code'): string
    {
        return "sms:{$suffix}:{$purpose}:{$phone}";
    }
}
