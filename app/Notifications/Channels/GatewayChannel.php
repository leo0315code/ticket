<?php

namespace App\Notifications\Channels;

use App\Support\GatewayPush;
use Illuminate\Notifications\Notification;

/**
 * 即时消息通道：把通知通过 GatewayWorker 实时推送给在线用户
 * （数据库通道仍负责离线消息，本通道只管"在线即时送达"）
 */
class GatewayChannel
{
    public function send($notifiable, Notification $notification)
    {
        if (! method_exists($notification, 'toGateway')) {
            return;
        }

        $payload = $notification->toGateway($notifiable);
        if (empty($payload) || ! $notifiable->id) {
            return;
        }

        GatewayPush::toUser($notifiable->id, [
            'type' => 'notification',
            ...$payload,
        ]);
    }
}
