<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Support\Settings;
use App\Notifications\Channels\GatewayChannel;
use App\Notifications\Channels\WeChatChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 工单事件通知（队列化：邮件等慢通道不再阻塞请求，php artisan queue:work 消费）
 * 开发环境 QUEUE_CONNECTION=sync 时行为与同步一致
 */
class TicketEventNotification extends Notification implements ShouldQueue
{
    use Queueable;
    public function __construct(public Ticket $ticket, public string $message, public string $event)
    {
    }

    /**
     * 通道选择：Gateway 实时推送必须同步（秒达），站内信+邮件走队列。
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        // 邮箱是选填项：没绑邮箱就没有邮件通道，避免队列任务发往空地址失败
        if (filled($notifiable->email ?? null)
            && ($notifiable->notify_email ?? true)
            && Settings::get('notify_mail', true)) {
            $channels[] = 'mail';
        }

        // 微信模板消息：只在客户侧事件（回复/状态更新）且已绑公众号时启用
        if (($notifiable->wechat_openid ?? null) && $this->wechatTemplateId()) {
            $channels[] = WeChatChannel::class;
        }

        $channels[] = GatewayChannel::class;

        return $channels;
    }

    /** 客户侧事件映射到模板：replied→新回复模板，updated/其余→状态模板；未配置返回 null */
    private function wechatTemplateId(): ?string
    {
        if (! Settings::get('wechat_notify_enabled', false)) {
            return null;
        }

        $id = $this->event === 'replied'
            ? Settings::get('wechat_reply_template_id', '')
            : Settings::get('wechat_status_template_id', '');

        return $id !== '' ? $id : null;
    }

    /**
     * 模板字段按“工单通知”类模板约定：first / keyword1(工单号) / keyword2(内容) / keyword3(时间) / remark。
     * 在公众号后台添加模板时请保持这个字段顺序。
     */
    public function toWeChat(object $notifiable): array
    {
        return [
            'template_id' => $this->wechatTemplateId(),
            'url' => url("/m/tickets/{$this->ticket->id}"),
            'data' => [
                'first' => $this->message,
                'keyword1' => $this->ticket->no,
                'keyword2' => mb_substr($this->ticket->title, 0, 20),
                'keyword3' => now()->format('Y-m-d H:i'),
                'remark' => '点击查看详情',
            ],
        ];
    }

    /** Gateway 通道用 sync 连接（当前请求内直接发送）；database/mail 走默认 database 队列 */
    public function viaConnections(): array
    {
        return [GatewayChannel::class => 'sync'];
    }

    /** Gateway 通道负载：前端 WebSocket 收到后即时刷新铃铛并弹 toast */
    public function toGateway(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'message' => $this->message,
            'ticket_id' => $this->ticket->id,
            'ticket_no' => $this->ticket->no,
            'title' => $this->ticket->title,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("【工单系统】{$this->ticket->no} {$this->message}")
            ->line($this->message)
            ->line("工单：{$this->ticket->no} {$this->ticket->title}")
            ->action('查看工单', url("/tickets/{$this->ticket->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_no' => $this->ticket->no,
            'title' => $this->ticket->title,
            'event' => $this->event,
            'message' => $this->message,
        ];
    }
}
