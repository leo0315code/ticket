<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketExportController extends Controller
{
    /** 导出工单 CSV（客服/管理员，支持与列表一致的筛选，UTF-8 BOM 兼容 Excel） */
    public function index(Request $request): StreamedResponse
    {
        $filename = '工单导出-'.now()->format('Ymd-His').'.csv';

        $query = Ticket::query()
            ->with(['user:id,name', 'agent:id,name', 'category:id,name'])
            ->withCount('replies')
            ->latest();

        if (! $request->user()->isStaff()) {
            $query->where('user_id', $request->user()->id);
        }

        $query->filter($request->only(['status', 'priority', 'category_id', 'keyword', 'mine', 'overdue']));

        $statusLabels = Ticket::STATUS_LABELS;
        $priorityLabels = Ticket::PRIORITY_LABELS;

        return response()->streamDownload(function () use ($query, $statusLabels, $priorityLabels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['工单号', '标题', '状态', '优先级', '分类', '提交人', '受理客服', '回复数', '满意度评分', 'SLA截止', '首次响应', '创建时间', '是否超时']);

            $query->chunk(500, function ($tickets) use ($out, $statusLabels, $priorityLabels) {
                foreach ($tickets as $t) {
                    fputcsv($out, [
                        $t->no,
                        $t->title,
                        $statusLabels[$t->status] ?? $t->status,
                        $priorityLabels[$t->priority] ?? $t->priority,
                        $t->category?->name ?? '',
                        $t->user?->name ?? '',
                        $t->agent?->name ?? '',
                        $t->replies_count,
                        $t->rating ?? '',
                        $t->sla_due_at?->format('Y-m-d H:i') ?? '',
                        $t->first_response_at?->format('Y-m-d H:i') ?? '',
                        $t->created_at->format('Y-m-d H:i'),
                        $t->isOverdue() ? '是' : '否',
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
