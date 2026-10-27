<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEventNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckTicketSla extends Command
{
    protected $signature = 'tickets:check-sla';

    protected $description = '检查工单 SLA：对超过响应时限的未处理工单通知客服并记录日志（每单只提醒一次）';

    public function handle(): int
    {
        $overdue = Ticket::with(['agent', 'user'])
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->whereDoesntHave('logs', fn ($q) => $q->where('action', 'sla_breach'))
            ->get();

        foreach ($overdue as $ticket) {
            $ticket->log('sla_breach', $ticket->agent_id ?? $ticket->user_id,
                '超过 SLA 响应时限（'.($ticket->sla_due_at?->format('Y-m-d H:i') ?? '').'）');

            $hours = $ticket->created_at->diffInHours(now());
            $message = "工单 {$ticket->no} 已超时 {$hours} 小时未响应（优先级：".(Ticket::PRIORITY_LABELS[$ticket->priority] ?? $ticket->priority).'）';

            $recipients = $ticket->agent_id
                ? User::where('id', $ticket->agent_id)->get()
                : User::staff()->get();

            Notification::send($recipients, new TicketEventNotification($ticket, $message, 'sla_breach'));

            $this->warn("SLA 超时：{$ticket->no} {$ticket->title}");
        }

        $this->checkResolutionSla();

        if ($overdue->isEmpty()) {
            $this->info('没有超时工单。');
        }

        return self::SUCCESS;
    }

    /** 解决时限超期：每 24 小时提醒一次（用日志去重，避免每小时轰炸） */
    private function checkResolutionSla(): void
    {
        $overdue = Ticket::with(['agent', 'user'])
            ->whereNotNull('resolution_due_at')
            ->whereNull('resolved_at')
            ->whereNotIn('status', ['pending_resolved', 'closed'])
            ->where('resolution_due_at', '<', now())
            ->whereDoesntHave('logs', fn ($q) => $q->where('action', 'resolution_overdue_alert')
                ->where('created_at', '>=', now()->subDay()))
            ->get();

        foreach ($overdue as $ticket) {
            $ticket->log('resolution_overdue_alert', null,
                '超过解决时限（'.($ticket->resolution_due_at?->format('Y-m-d H:i') ?? '').'）');

            $message = "工单 {$ticket->no} 已超过解决时限（优先级：".(Ticket::PRIORITY_LABELS[$ticket->priority] ?? $ticket->priority).'）';

            $recipients = $ticket->agent_id
                ? User::where('id', $ticket->agent_id)->get()
                : User::staff()->get();

            Notification::send($recipients, new TicketEventNotification($ticket, $message, 'resolution_overdue'));

            $this->warn("解决超期：{$ticket->no} {$ticket->title}");
        }
    }
}
