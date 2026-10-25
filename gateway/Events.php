<?php
/**
 * GatewayWorker 业务事件：WebSocket 连接鉴权与绑定
 *
 * 鉴权方式：前端连接 ws://127.0.0.1:1238?token=<sanctum token>
 * Worker 直接查询同一 MySQL 库校验 personal_access_tokens，通过后 bindUid 绑定用户，
 * Laravel 侧用 GatewayClient::sendToUid('u{uid}') 即可实时推送。
 */

use GatewayWorker\Lib\Gateway;

class Events
{
    public static ?PDO $pdo = null;

    public static function onWorkerStart($businessWorker)
    {
    }

    public static function onConnect($client_id)
    {
    }

    public static function onWebSocketConnect($client_id, $data)
    {
        $token = $data['get']['token'] ?? '';
        $userId = self::resolveUser($token);

        if (! $userId) {
            Gateway::sendToClient($client_id, json_encode(['type' => 'auth_failed']));
            Gateway::closeClient($client_id);

            return;
        }

        Gateway::bindUid($client_id, 'u'.$userId);
        Gateway::sendToClient($client_id, json_encode(['type' => 'connected', 'user_id' => $userId]));
    }

    public static function onMessage($client_id, $message)
    {
        // 客户端心跳
        if (trim($message) === 'ping') {
            Gateway::sendToClient($client_id, 'pong');
        }
    }

    public static function onClose($client_id)
    {
    }

    /** 校验 Sanctum token（格式 "id|明文"，与 Laravel 校验逻辑一致），返回 user_id 或 null */
    private static function resolveUser(string $token): ?int
    {
        if (! str_contains($token, '|')) {
            return null;
        }

        [$id, $plain] = explode('|', $token, 2);

        try {
            $st = self::pdo()->prepare(
                'SELECT tokenable_id FROM personal_access_tokens WHERE id = ? AND token = ?'
            );
            $st->execute([(int) $id, hash('sha256', $plain)]);
            $uid = $st->fetchColumn();

            return $uid ? (int) $uid : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_CONNECTION') ?: 'mysql',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_DATABASE') ?: 'ticket'
            );
            self::$pdo = new PDO(
                $dsn,
                getenv('DB_USERNAME') ?: 'root',
                getenv('DB_PASSWORD') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }

        return self::$pdo;
    }
}
