<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEventNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Ticket::query()
            ->with(['user:id,name', 'agent:id,name', 'category:id,name'])
            ->withCount('replies')
            ->latest();

        if (! $request->user()->isStaff()) {
            $query->where('user_id', $request->user()->id);
        }

        // mine 前端只传 1，后端换算为当前用户 id（只看我受理的）
        $filters = $request->only(['status', 'priority', 'category_id', 'keyword', 'mine', 'overdue']);
        if (! empty($filters['mine'])) {
            $filters['mine'] = $request->user()->id;
        }

        $query->filter($filters);

        return TicketResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
            'category_id' => ['required', Rule::exists('categories', 'id')],
        ]);

        $ticket = $request->user()->tickets()->create([
            ...$data,
            'no' => Ticket::nextNo(),
            'status' => 'open',
            'sla_due_at' => now()->addHours(Ticket::SLA_HOURS[$data['priority']] ?? 24),
        ]);
        $ticket->log('created', $request->user()->id, "创建工单【{$ticket->title}】");

        // 通知全部客服：有新工单
        Notification::send(User::whereIn('role', ['agent', 'admin'])->get(),
            new TicketEventNotification($ticket, "{$request->user()->name} 提交了新工单：{$ticket->title}", 'created'));

        return response()->json(new TicketResource($ticket->load('category')), 201);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return response()->json(new TicketResource(
            $ticket->load(['user:id,name', 'agent:id,name', 'category:id,name'])
                ->load([
                    'replies' => fn ($q) => $q->with('user:id,name,role')->oldest(),
                    'logs' => fn ($q) => $q->with('user:id,name')->oldest(),
                    'attachments' => fn ($q) => $q->with('user:id,name')->oldest(),
                ])
        ));
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);
        $user = $request->user();

        if ($user->isStaff()) {
            $data = $request->validate([
                'status' => ['sometimes', Rule::in(Ticket::STATUSES)],
                'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)],
                'category_id' => ['sometimes', 'nullable', Rule::exists('categories', 'id')],
                'agent_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')->whereIn('role', ['agent', 'admin'])],
            ]);

            if (($data['status'] ?? null) === 'pending_resolved') {
                $data['resolved_at'] ??= now();
            }
            if (($data['status'] ?? null) === 'closed') {
                $data['closed_at'] ??= now();
            }
        } else {
            if ($ticket->user_id !== $user->id || $ticket->status !== 'open') {
                return response()->json(['message' => '仅待处理状态下的本人工单可编辑'], 403);
            }

            $data = $request->validate([
                'title' => ['required', 'string', 'max:100'],
                'content' => ['required', 'string', 'max:5000'],
            ]);
        }

        // 记录变更日志并通知
        foreach (['status', 'priority', 'agent_id', 'category_id'] as $field) {
            if (! array_key_exists($field, $data) || ($ticket->$field == ($data[$field] ?? null))) {
                continue;
            }
            $old = $ticket->$field;
            match ($field) {
                'status' => $ticket->log('status_changed', $user->id, "状态：".(Ticket::STATUS_LABELS[$old] ?? $old)." → ".(Ticket::STATUS_LABELS[$data[$field]] ?? $data[$field])),
                'priority' => $ticket->log('priority_changed', $user->id, "优先级：".(Ticket::PRIORITY_LABELS[$old] ?? $old)." → ".(Ticket::PRIORITY_LABELS[$data[$field]] ?? $data[$field])),
                'agent_id' => $ticket->log('assigned', $user->id, $data[$field] ? '指派给'.(User::find($data[$field])?->name ?? '客服') : '取消指派'),
                'category_id' => $ticket->log('category_changed', $user->id, "分类调整"),
                default => null,
            };
        }

        $ticket->update($data);

        // 通知提交人（客服操作时）
        if ($user->isStaff() && $ticket->user_id !== $user->id) {
            $msg = match (true) {
                (isset($data['status']) && $data['status'] === 'closed') => "您的工单 {$ticket->no} 已关闭，问题是否已解决？欢迎点击评价本次服务",
                isset($data['status']) => "您的工单 {$ticket->no} 状态更新为「".(Ticket::STATUS_LABELS[$data['status']] ?? $data['status'])."」",
                isset($data['agent_id']) => "您的工单 {$ticket->no} 已被受理",
                default => "您的工单 {$ticket->no} 信息已更新",
            };
            $ticket->user->notify(new TicketEventNotification($ticket, $msg, 'updated'));
        }
        // 指派时通知新客服
        if (isset($data['agent_id']) && $data['agent_id'] && $data['agent_id'] !== $user->id) {
            $assignee = User::find($data['agent_id']);
            $assignee?->notify(new TicketEventNotification($ticket, "工单 {$ticket->no} 已指派给您", 'assigned'));
        }

        return response()->json(new TicketResource(
            $ticket->load(['user:id,name', 'agent:id,name', 'category:id,name'])->loadCount('replies')
        ));
    }

    /** 批量操作：批量关闭 / 批量指派（staff） */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer', 'exists:tickets,id'],
            'action' => ['required', 'in:close,assign'],
            'agent_id' => ['required_if:action,assign', 'nullable', Rule::exists('users', 'id')->whereIn('role', ['agent', 'admin'])],
        ]);

        $tickets = Ticket::whereIn('id', $data['ids'])->get();
        $count = 0;

        foreach ($tickets as $ticket) {
            if ($data['action'] === 'close') {
                if ($ticket->status === 'closed') {
                    continue;
                }
                $ticket->update([
                    'status' => 'closed',
                    'resolved_at' => $ticket->resolved_at ?? now(),
                    'closed_at' => $ticket->closed_at ?? now(),
                ]);
                $ticket->log('status_changed', $request->user()->id, '状态：已关闭（批量操作）');
                $ticket->user->notify(new \App\Notifications\TicketEventNotification(
                    $ticket, "您的工单 {$ticket->no} 已被关闭，欢迎评价本次服务", 'updated'
                ));
            } else {
                $agent = User::find($data['agent_id']);
                if (! $agent || $ticket->agent_id === $agent->id) {
                    continue;
                }
                $oldName = $ticket->agent?->name ?? '未指派';
                $ticket->update(['agent_id' => $agent->id]);
                $ticket->log('assigned', $request->user()->id, "{$oldName} → {$agent->name}（批量指派）");
            }
            $count++;
        }

        return response()->json(['message' => "已处理 {$count} 张工单", 'count' => $count]);
    }

    /** 提交人满意度评价（仅已关闭且未评价） */

    public function rate(Request $request, Ticket $ticket): JsonResponse
    {
        if ($ticket->user_id !== $request->user()->id) {
            return response()->json(['message' => '仅工单提交人可评价'], 403);
        }
        if ($ticket->status !== 'closed') {
            return response()->json(['message' => '工单关闭后才能评价'], 422);
        }
        if ($ticket->rating) {
            return response()->json(['message' => '已评价过'], 422);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'rating_comment' => ['nullable', 'string', 'max:200'],
        ]);

        $ticket->update($data);
        $ticket->log('rated', $request->user()->id, "{$data['rating']} 星".(isset($data['rating_comment']) && $data['rating_comment'] ? "（{$data['rating_comment']}）" : ''));

        return response()->json(new TicketResource($ticket->load(['user:id,name', 'agent:id,name', 'category:id,name'])));
    }

    public function destroy(Ticket $ticket): JsonResponse
    {
        $this->authorize('delete', $ticket);
        $ticket->delete();

        return response()->json(['message' => '工单已删除']);
    }
}
