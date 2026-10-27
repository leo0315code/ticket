<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Support\Settings;
use App\Notifications\Channels\GatewayChannel;
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

        $channels[] = GatewayChannel::class;

        return $channels;
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
