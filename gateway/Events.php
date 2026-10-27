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
        $get = $data['get'] ?? [];
        // 优先一次性 ticket；token 参数保留兼容老前端（建议升级后移除）
        $user = ! empty($get['ticket'])
            ? self::consumeTicket((string) $get['ticket'])
            : self::resolveUser((string) ($get['token'] ?? ''));

        if (! $user) {
            // token 无效 / 已过期 / 账号已停用，统一拒绝，不泄露具体原因
            Gateway::sendToClient($client_id, json_encode(['type' => 'auth_failed']));
            Gateway::closeClient($client_id);

            return;
        }

        Gateway::bindUid($client_id, 'u'.$user['id']);
        // 带上角色，前端/网关都能据此区分前台用户与后台坐席
        Gateway::sendToClient($client_id, json_encode([
            'type' => 'connected',
            'user_id' => $user['id'],
            'role' => $user['role'],
            'is_staff' => $user['is_staff'],
        ]));
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

    /**
     * 校验 Sanctum token（格式 "id|明文"，与 Laravel 校验逻辑一致）
     * 同时校验 token 是否过期、账号是否已停用；返回用户信息或 null。
     */
    private static function resolveUser(string $token): ?array
    {
        if (! str_contains($token, '|')) {
            return null;
        }

        [$id, $plain] = explode('|', $token, 2);

        try {
            $st = self::pdo()->prepare(
                'SELECT p.tokenable_id AS uid, p.expires_at, u.role, u.is_active
                   FROM personal_access_tokens p
                   JOIN users u ON u.id = p.tokenable_id
                  WHERE p.id = ? AND p.token = ?'
            );
            $st->execute([(int) $id, hash('sha256', $plain)]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (! $row) {
                return null;
            }

            // token 过期（Sanctum 默认 30 天）或账号停用，都不允许建立实时连接
            $expired = $row['expires_at'] !== null && strtotime($row['expires_at']) <= time();
            if ($expired || (int) $row['is_active'] !== 1) {
                return null;
            }

            return [
                'id' => (int) $row['uid'],
                'role' => (string) $row['role'],
                'is_staff' => self::isStaffRole((string) $row['role']),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 校验并作废一次性连接票据（ws_tickets，60 秒有效、只能用一次）
     * UPDATE 带 used_at IS NULL 条件，天然防并发重放。
     */
    private static function consumeTicket(string $ticket): ?array
    {
        if ($ticket === '') {
            return null;
        }

        try {
            $hash = hash('sha256', $ticket);
            $st = self::pdo()->prepare(
                'UPDATE ws_tickets SET used_at = NOW()
                  WHERE ticket_hash = ? AND used_at IS NULL AND expires_at > NOW()'
            );
            $st->execute([$hash]);

            if ($st->rowCount() !== 1) {
                return null;
            }

            $st = self::pdo()->prepare(
                'SELECT w.user_id, u.role, u.is_active
                   FROM ws_tickets w
                   JOIN users u ON u.id = w.user_id
                  WHERE w.ticket_hash = ?'
            );
            $st->execute([$hash]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (! $row || (int) $row['is_active'] !== 1) {
                return null;
            }

            return [
                'id' => (int) $row['user_id'],
                'role' => (string) $row['role'],
                'is_staff' => self::isStaffRole((string) $row['role']),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** 角色是否属于后台坐席（以 roles 表的 panel.access 权限为准，兼容自定义角色） */
    private static function isStaffRole(string $role): bool
    {
        if (in_array($role, ['agent', 'admin'], true)) {
            return true;
        }

        try {
            $st = self::pdo()->prepare('SELECT permissions FROM roles WHERE name = ?');
            $st->execute([$role]);
            $permissions = json_decode((string) $st->fetchColumn(), true) ?: [];

            return in_array('panel.access', $permissions, true) || in_array('*', $permissions, true);
        } catch (Throwable $e) {
            return false;
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
