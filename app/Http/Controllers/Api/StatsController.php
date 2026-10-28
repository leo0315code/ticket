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
        // 聚合查询一次取全量，避免每个坐席再各查一遍（原实现 O(坐席×工单)）
        $responseByAgent = Ticket::whereNotNull('agent_id')->whereNotNull('first_response_at')
            ->get(['agent_id', 'created_at', 'first_response_at'])
            ->groupBy('agent_id')
            ->map(fn ($rows) => $rows->avg(fn ($t) => $t->created_at->diffInMinutes($t->first_response_at)));

        $ratingByAgent = Ticket::whereNotNull('agent_id')->whereNotNull('rating')
            ->groupBy('agent_id')
            ->selectRaw('agent_id, AVG(rating) as avg_rating')
            ->pluck('avg_rating', 'agent_id');

        $byAgent = User::staff()
            ->withCount([
                'assignedTickets as assigned_count',
                'assignedTickets as closed_count' => fn ($q) => $q->where('status', 'closed'),
            ])
            ->get()
            ->map(function ($u) use ($responseByAgent, $ratingByAgent) {
                $avgResponse = $responseByAgent[$u->id] ?? null;
                $avgRating = $ratingByAgent[$u->id] ?? null;

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'assigned' => (int) $u->assigned_count,
                    'closed' => (int) $u->closed_count,
                    'avg_response_min' => $avgResponse !== null ? round($avgResponse, 1) : null,
                    'avg_rating' => $avgRating !== null ? round((float) $avgRating, 1) : null,
                ];
            })->values();

        // 时间区间：新增 / 关闭 / 首次响应合规率（SLA 只看首次响应）
        $days = in_array($request->integer('days'), [7, 30, 90], true) ? $request->integer('days') : 30;
        $from = now()->subDays($days)->startOfDay();

        $responded = Ticket::where('created_at', '>=', $from)
            ->whereNotNull('first_response_at')->whereNotNull('sla_due_at');
        $respondedCount = (clone $responded)->count();
        $onTimeCount = (clone $responded)->whereColumn('first_response_at', '<=', 'sla_due_at')->count();

        // 解决时限合规率：只看区间内已解决且算得出期限的工单
        $resolved = Ticket::where('created_at', '>=', $from)
            ->whereNotNull('resolved_at')->whereNotNull('resolution_due_at');
        $resolvedCount = (clone $resolved)->count();
        $resolvedOnTime = (clone $resolved)->whereColumn('resolved_at', '<=', 'resolution_due_at')->count();

        return response()->json([
            'by_agent' => $byAgent,
            'range' => [
                'days' => $days,
                'new' => Ticket::where('created_at', '>=', $from)->count(),
                'closed' => Ticket::where('created_at', '>=', $from)->where('status', 'closed')->count(),
                'responded' => $respondedCount,
                'on_time' => $onTimeCount,
                'sla_rate' => $respondedCount > 0 ? round($onTimeCount / $respondedCount * 100, 1) : null,
                'resolved' => $resolvedCount,
                'resolved_on_time' => $resolvedOnTime,
                'resolution_rate' => $resolvedCount > 0 ? round($resolvedOnTime / $resolvedCount * 100, 1) : null,
                'resolution_overdue' => Ticket::whereNotNull('resolution_due_at')
                    ->whereNull('resolved_at')->whereNotIn('status', ['pending_resolved', 'closed'])
                    ->where('resolution_due_at', '<', now())->count(),
            ],
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

    /** 导出统计 CSV：按天的新增/关闭 + 区间汇总 + 坐席绩效 */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $days = in_array($request->integer('days'), [7, 30, 90], true) ? $request->integer('days') : 30;
        $from = now()->subDays($days - 1)->startOfDay();
        $filename = "工单统计-近{$days}天-".now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($days, $from) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['按天明细']);
            fputcsv($out, ['日期', '新增', '关闭', '首次响应数', 'SLA 达标数']);
            for ($i = 0; $i < $days; $i++) {
                $day = (clone $from)->addDays($i);
                $responded = Ticket::whereDate('created_at', $day)
                    ->whereNotNull('first_response_at')->whereNotNull('sla_due_at');
                fputcsv($out, [
                    $day->format('Y-m-d'),
                    Ticket::whereDate('created_at', $day)->count(),
                    Ticket::whereDate('closed_at', $day)->count(),
                    (clone $responded)->count(),
                    (clone $responded)->whereColumn('first_response_at', '<=', 'sla_due_at')->count(),
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['坐席绩效（全量）']);
            fputcsv($out, ['客服', '受理数', '已关闭', '平均首次响应(分钟)', '满意度均分']);

            $responseByAgent = Ticket::whereNotNull('agent_id')->whereNotNull('first_response_at')
                ->get(['agent_id', 'created_at', 'first_response_at'])
                ->groupBy('agent_id')
                ->map(fn ($rows) => $rows->avg(fn ($t) => $t->created_at->diffInMinutes($t->first_response_at)));
            $ratingByAgent = Ticket::whereNotNull('agent_id')->whereNotNull('rating')
                ->groupBy('agent_id')->selectRaw('agent_id, AVG(rating) as avg_rating')->pluck('avg_rating', 'agent_id');

            User::staff()
                ->withCount([
                    'assignedTickets as assigned_count',
                    'assignedTickets as closed_count' => fn ($q) => $q->where('status', 'closed'),
                ])->get()->each(function ($u) use ($out, $responseByAgent, $ratingByAgent) {
                    fputcsv($out, [
                        $u->name,
                        (int) $u->assigned_count,
                        (int) $u->closed_count,
                        isset($responseByAgent[$u->id]) ? round($responseByAgent[$u->id], 1) : '',
                        isset($ratingByAgent[$u->id]) ? round((float) $ratingByAgent[$u->id], 1) : '',
                    ]);
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** 某客服的评价明细（staff）：已评价工单列表，按评分升序（差评在前） */
    public function ratings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $rows = Ticket::where('agent_id', $data['agent_id'])
            ->whereNotNull('rating')
            ->with('user:id,name')
            ->orderBy('rating') // 差评在前（1 星最前）
            ->orderByDesc('id')
            ->get(['id', 'no', 'title', 'rating', 'rating_comment', 'user_id', 'created_at'])
            ->map(fn ($t) => [
                'ticket_id' => $t->id,
                'no' => $t->no,
                'title' => $t->title,
                'rating' => $t->rating,
                'comment' => $t->rating_comment,
                'user' => $t->user?->name,
                'created_at' => $t->created_at->format('Y-m-d'),
            ]);

        return response()->json(['data' => $rows->values()]);
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
