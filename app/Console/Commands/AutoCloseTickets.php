<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Support\Settings;
use Illuminate\Console\Command;

class AutoCloseTickets extends Command
{
    protected $signature = 'tickets:auto-close {--days=}';

    protected $description = '待确认工单超过 N 天无活动后自动关闭（客户可再重开）';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: Settings::get('auto_close_days', 7)));
        $deadline = now()->subDays($days);

        $stale = Ticket::where('status', 'pending_resolved')
            ->where(fn ($q) => $q->where('last_activity_at', '<', $deadline)
                ->orWhere(fn ($q) => $q->whereNull('last_activity_at')->where('updated_at', '<', $deadline)))
            ->get();

        foreach ($stale as $ticket) {
            $ticket->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_reason' => 'auto',
                'last_activity_at' => now(),
            ]);
            $ticket->log('status_changed', $ticket->agent_id ?? $ticket->user_id,
                "待确认超过 {$days} 天无回复，系统自动关闭");
        }

        $this->info("自动关闭 {$stale->count()} 张工单。");

        return self::SUCCESS;
    }
}
