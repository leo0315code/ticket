<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
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

        return response()->json([
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
