<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $byStatus = [];
        foreach (Ticket::STATUSES as $status) {
            $byStatus[$status] = Ticket::where('status', $status)->count();
        }

        $byPriority = [];
        foreach (Ticket::PRIORITIES as $priority) {
            $byPriority[$priority] = Ticket::where('priority', $priority)->count();
        }

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = today()->subDays($i);
            $trend[] = [
                'date' => $day->format('m-d'),
                'count' => Ticket::whereDate('created_at', $day)->count(),
            ];
        }

        // 客服绩效：受理数 / 已关闭数 / 平均首次响应（分钟）/ 满意度均分
        $byAgent = User::whereIn('role', ['agent', 'admin'])
            ->withCount([
                'assignedTickets as assigned_count',
                'assignedTickets as closed_count' => fn ($q) => $q->where('status', 'closed'),
            ])
            ->get()
            ->map(function ($u) {
                // PHP 层计算平均响应（分钟），兼容 SQLite/MySQL
                $responseTimes = Ticket::where('agent_id', $u->id)->whereNotNull('first_response_at')
                    ->get(['created_at', 'first_response_at'])
                    ->map(fn ($t) => $t->created_at->diffInMinutes($t->first_response_at));
                $avgResponse = $responseTimes->count() > 0 ? $responseTimes->avg() : null;
                $avgRating = Ticket::where('agent_id', $u->id)->whereNotNull('rating')->avg('rating');

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'assigned' => (int) $u->assigned_count,
                    'closed' => (int) $u->closed_count,
                    'avg_response_min' => $avgResponse !== null ? round($avgResponse, 1) : null,
                    'avg_rating' => $avgRating !== null ? round($avgRating, 1) : null,
                ];
            })->values();

        return response()->json([
            'by_agent' => $byAgent,
            'total' => Ticket::count(),
            'unhandled' => $byStatus['open'] + $byStatus['in_progress'],
            'overdue' => Ticket::whereIn('status', ['open', 'in_progress'])
                ->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->count(),
            'today_new' => Ticket::whereDate('created_at', today())->count(),
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'trend' => $trend,
        ]);
    }

    /** 当前用户的工单概览（各状态计数） */
    public function mine(Request $request): JsonResponse
    {
        $base = Ticket::where('user_id', $request->user()->id);
        $byStatus = [];
        foreach (Ticket::STATUSES as $status) {
            $byStatus[$status] = (clone $base)->where('status', $status)->count();
        }

        return response()->json([
            'total' => $base->count(),
            'by_status' => $byStatus,
        ]);
    }
}
