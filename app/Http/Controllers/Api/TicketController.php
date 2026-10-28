<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Support\Settings;
use App\Models\User;
use App\Notifications\TicketEventNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Ticket::query()
            ->visibleTo($request->user())
            ->with(['user:id,name', 'agent:id,name', 'category:id,name'])
            ->withCount('replies')
            ->latest();

        // mine 前端只传 1，后端换算为当前用户 id（只看我受理的）
        $filters = $request->only(['status', 'priority', 'category_id', 'keyword', 'mine', 'overdue']);
        if (! empty($filters['mine'])) {
            $filters['mine'] = $request->user()->id;
        }

        $query->filter($filters);

        $perPage = min(max($request->integer('per_page', 10), 1), 100);

        return TicketResource::collection($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
            'category_id' => ['required', Rule::exists('categories', 'id')],
        ]);

        // 工单号按“当日最大序号 +1”生成，并发下可能撞唯一索引：撞了就重算重试
        $ticket = retry(5, fn () => $request->user()->tickets()->create([
            ...$data,
            'no' => Ticket::nextNo(),
            'status' => 'open',
            'sla_due_at' => now()->addHours(Ticket::slaHours($data['priority'])),
            'resolution_due_at' => Ticket::resolutionDueFor($data['priority']),
            'last_activity_at' => now(),
        ]), 30);
        $ticket->log('created', $request->user()->id, "创建工单【{$ticket->title}】");

        // 自动分配（系统设置开启时）：分类默认客服 → 否则未结工单最少的客服
        if (Settings::get('auto_assign', false) && $ticket->agent_id === null) {
            if ($assignee = Ticket::pickAssignee($ticket->category)) {
                $ticket->update(['agent_id' => $assignee->id, 'last_activity_at' => now()]);
                $ticket->log('assigned', $request->user()->id, "自动分配给 {$assignee->name}");
                $assignee->notify(new TicketEventNotification(
                    $ticket, "新工单自动分配给你：{$ticket->title}", 'assigned'
                ));
            }
        }

        // 通知全部客服：有新工单
        Notification::send(User::staff()->get(),
            new TicketEventNotification($ticket, "{$request->user()->name} 提交了新工单：{$ticket->title}", 'created'));

        return response()->json(new TicketResource($ticket->load(['category', 'agent:id,name'])), 201);
    }

    /** 关注/取消关注工单（参与者均可） */
    public function watch(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $ticket->watchers()->syncWithoutDetaching([$request->user()->id]);

        return response()->json(['watching' => true, 'count' => $ticket->watchers()->count()]);
    }

    public function unwatch(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $ticket->watchers()->detach($request->user()->id);

        return response()->json(['watching' => false, 'count' => $ticket->watchers()->count()]);
    }

    /**
     * 合并工单：把来源单的回复/附件搬到目标单，来源单关闭并指向目标单。
     * 典型场景：客户重复提交、同一问题多人反馈。
     */
    public function merge(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);
        if (! $request->user()->hasPermission('ticket.close')) {
            return response()->json(['message' => '没有合并工单的权限'], 403);
        }

        $data = $request->validate([
            'target_no' => ['required', 'string', 'max:40'],
        ]);

        if ($ticket->merged_into_id) {
            return response()->json(['message' => '该工单已经合并过了'], 422);
        }

        $target = Ticket::where('no', $data['target_no'])->first();
        if (! $target || $target->id === $ticket->id) {
            return response()->json(['message' => '目标工单不存在'], 422);
        }
        if ($target->merged_into_id) {
            return response()->json(['message' => '目标工单本身已被合并，请选择最终工单'], 422);
        }

        DB::transaction(function () use ($ticket, $target, $request) {
            $ticket->replies()->update(['ticket_id' => $target->id]);
            $ticket->attachments()->update(['ticket_id' => $target->id]);

            $ticket->update([
                'status' => 'closed',
                'closed_reason' => 'merged',
                'merged_into_id' => $target->id,
                'resolved_at' => now(),
                'closed_at' => now(),
                'last_activity_at' => now(),
            ]);

            $ticket->log('merged', $request->user()->id, "合并到工单 {$target->no}");
            $target->log('merged_in', $request->user()->id, "合并入工单 {$ticket->no}");
            $target->update(['last_activity_at' => now()]);
        });

        if ($ticket->user_id !== $request->user()->id) {
            $ticket->user?->notify(new \App\Notifications\TicketEventNotification(
                $ticket,
                "您的工单 {$ticket->no} 已合并到 {$target->no} 继续跟进，后续回复请在 {$target->no} 中查看",
                'merged',
            ));
        }

        return response()->json(new TicketResource(
            $ticket->load(['mergedInto:id,no,title', 'user:id,name', 'agent:id,name'])->loadCount('replies')
        ));
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $isStaff = $request->user()->isStaff();

        $ticket->load(['user:id,name', 'agent:id,name', 'category:id,name']);
        // 关注者名单只给 staff；客户只看自己是否关注 + 人数
        $ticket->load($isStaff
            ? ['watchers:id,name,role']
            : ['watchers:id']);
        // 只返回最新一页回复（30 条），更早的通过 GET /tickets/{id}/replies?before_id= 翻页加载
        $ticket->load([
            'replies' => fn ($q) => $q->with('user:id,name,role')->orderByDesc('id')
                ->when(! $isStaff, fn ($q) => $q->where('is_internal', false))
                ->limit(30),
            'attachments' => fn ($q) => $q->with('user:id,name')->oldest(),
        ]);
        $ticket->setRelation('replies', $ticket->replies->reverse()->values());
        // replies_count 作为可加载的总条数（客户会排除内部备注）
        $ticket->loadCount(['replies' => fn ($q) => $q->when(! $isStaff, fn ($q) => $q->where('is_internal', false))]);
        // 操作日志含内部分配/优先级调整等，仅工作人员可见
        if ($isStaff) {
            $ticket->load(['logs' => fn ($q) => $q->with('user:id,name')->oldest()]);
        }

        return response()->json(new TicketResource($ticket));
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
                'agent_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where('is_active', true)],
            ]);

            if (array_key_exists('agent_id', $data) && ! $user->hasPermission('ticket.assign')) {
                return response()->json(['message' => '没有指派工单的权限'], 403);
            }

            $newStatus = $data['status'] ?? null;
            if ($newStatus === 'pending_resolved') {
                $data['resolved_at'] ??= now();
            }
            if ($newStatus === 'closed') {
                $data['resolved_at'] ??= now();
                $data['closed_at'] ??= now();
                $data['closed_reason'] ??= 'manual';
            }
            // 状态回退（重新打开）时清掉解决/关闭时间，避免留下脏时间戳
            if (in_array($newStatus, ['open', 'in_progress'], true)) {
                $data['resolved_at'] = null;
                $data['closed_at'] = null;
                $data['closed_reason'] = null;
            }

            // 优先级变化后按新优先级重算响应时限（以创建时间为基准，不因改优先级而白送时限）
            if (isset($data['priority']) && $data['priority'] !== $ticket->priority) {
                $data['sla_due_at'] = $ticket->created_at->copy()
                    ->addHours(Ticket::slaHours($data['priority']));
                $data['resolution_due_at'] = Ticket::resolutionDueFor($data['priority'], $ticket->created_at);
            }
            $data['last_activity_at'] = now();
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
        // 关注者：状态变化时通知（提交人/受理人已单独通知，不重复）
        if (isset($data['status'])) {
            $watchers = $ticket->watchers()
                ->whereNotIn('users.id', array_filter([$ticket->user_id, $ticket->agent_id, $user->id]))
                ->get();
            $watchers->each(fn ($w) => $w->notify(new TicketEventNotification(
                $ticket,
                "您关注的工单 {$ticket->no} 状态更新为「".(Ticket::STATUS_LABELS[$data['status']] ?? $data['status']).'」',
                'watched',
            )));
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
            'agent_id' => ['required_if:action,assign', 'nullable', Rule::exists('users', 'id')->where('is_active', true)],
        ]);

        $needed = $data['action'] === 'close' ? 'ticket.close' : 'ticket.assign';
        if (! $request->user()->hasPermission($needed)) {
            return response()->json(['message' => '没有该操作权限'], 403);
        }

        $tickets = Ticket::whereIn('id', $data['ids'])->get();
        $count = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($tickets, $data, $request, &$count) {
        foreach ($tickets as $ticket) {
            if ($data['action'] === 'close') {
                if ($ticket->status === 'closed') {
                    continue;
                }
                $ticket->update([
                    'status' => 'closed',
                    'resolved_at' => $ticket->resolved_at ?? now(),
                    'closed_at' => $ticket->closed_at ?? now(),
                    'closed_reason' => 'manual',
                    'last_activity_at' => now(),
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
                $ticket->update(['agent_id' => $agent->id, 'last_activity_at' => now()]);
                $ticket->log('assigned', $request->user()->id, "{$oldName} → {$agent->name}（批量指派）");
                if ($agent->id !== $request->user()->id) {
                    $agent->notify(new TicketEventNotification($ticket, "工单 {$ticket->no} 已指派给您", 'assigned'));
                }
            }
            $count++;
        }
        });

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

    /** 客户重新打开已关闭的工单（关闭后 14 天内、最多 3 次） */
    public function reopen(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $user = $request->user();

        if ($ticket->user_id !== $user->id) {
            return response()->json(['message' => '仅工单提交人可重新打开'], 403);
        }
        if ($ticket->status !== 'closed') {
            return response()->json(['message' => '只有已关闭的工单可以重新打开'], 422);
        }
        $days = (int) Settings::get('reopen_days', 14);
        $limit = (int) Settings::get('reopen_limit', 3);
        if ($ticket->closed_at && $ticket->closed_at->lt(now()->subDays($days))) {
            return response()->json(['message' => "工单关闭已超过 {$days} 天，请新建工单"], 422);
        }
        if ((int) $ticket->reopen_count >= $limit) {
            return response()->json(['message' => "该工单已重开 {$limit} 次，请新建工单"], 422);
        }

        $ticket->update([
            'status' => 'open',
            'resolved_at' => null,
            'closed_at' => null,
            'closed_reason' => null,
            'reopen_count' => (int) $ticket->reopen_count + 1,
            'last_activity_at' => now(),
        ]);
        $ticket->log('reopened', $user->id, '客户重新打开工单');

        $recipients = $ticket->agent_id
            ? User::where('id', $ticket->agent_id)->get()
            : User::staff()->get();
        Notification::send($recipients, new TicketEventNotification(
            $ticket, "{$user->name} 重新打开了工单 {$ticket->no}", 'reopened'
        ));

        return response()->json(new TicketResource(
            $ticket->load(['user:id,name', 'agent:id,name', 'category:id,name'])->loadCount('replies')
        ));
    }

    public function destroy(Ticket $ticket): JsonResponse
    {
        $this->authorize('delete', $ticket);
        $ticket->delete();

        return response()->json(['message' => '工单已删除']);
    }

    /** 回收站：只列软删除的工单（需 ticket.delete 权限） */
    public function trash(Request $request): AnonymousResourceCollection
    {
        $perPage = min(100, max(1, $request->integer('per_page', 20)));
        $tickets = Ticket::onlyTrashed()
            ->filter($request->only(['keyword']))
            ->with(['user:id,name', 'agent:id,name', 'category:id,name'])
            ->orderByDesc('deleted_at')
            ->paginate($perPage);

        return TicketResource::collection($tickets);
    }

    /** 从回收站恢复工单 */
    public function restore(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::onlyTrashed()->findOrFail($id);
        $ticket->restore();
        $ticket->forceFill(['last_activity_at' => now()])->save();
        $ticket->log('restored', $request->user()->id, '从回收站恢复');

        return response()->json(['message' => '工单已恢复']);
    }
}
