<?php

namespace App\Support;

use GatewayClient\Gateway;

/**
 * GatewayWorker 实时推送助手（同步阻塞，适合低频事件推送）
 * 网关未启动时静默降级，不影响主流程。
 */
class GatewayPush
{
    public static function toUser(int $userId, array $payload): void
    {
        self::toUsers([$userId], $payload);
    }

    public static function toUsers(array $userIds, array $payload): void
    {
        if (empty($userIds)) {
            return;
        }

        try {
            Gateway::$registerAddress = config('gateway.register_address', '127.0.0.1:1236');
            foreach (array_unique($userIds) as $uid) {
                Gateway::sendToUid('u'.$uid, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
