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

        return Settings::get('sms_driver') === 'http'
            ? new HttpSmsSender((string) Settings::get('sms_http_url'), (string) Settings::get('sms_sign', '工单支持中心'))
            : new LogSmsSender();
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
