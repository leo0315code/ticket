<?php

namespace App\Notifications\Channels;

use App\Support\WeChat;
use Illuminate\Notifications\Notification;

class WeChatChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $payload = $notification->toWeChat($notifiable);

        if (empty($payload['template_id']) || empty($notifiable->wechat_openid)) {
            return;
        }

        WeChat::sendTemplateMessage(
            $notifiable->wechat_openid,
            $payload['template_id'],
            $payload['url'] ?? null,
            $payload['data'] ?? [],
        );
    }
}
