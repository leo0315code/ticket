<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketReplyResource;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEventNotification;
use App\Support\GatewayPush;
use App\Models\TicketReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;

class ReplyController extends Controller
{
    /**
     * 回复列表（向前翻页）。
     *
     * 默认返回最新 30 条，按时间正序；传 `before_id` 取更早的一页，
     * 这样新回复插入时不会造成翻页重复/遗漏。
     */
    public function index(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        $this->authorize('view', $ticket);

        $perPage = min(max($request->integer('per_page', 30), 1), 100);
        $query = $ticket->replies()->with('user:id,name,role')->orderByDesc('id');
        if (! $request->user()->isStaff()) {
            // 内部备注对客户完全不可见
            $query->where('is_internal', false);
        }
        if ($before = $request->integer('before_id')) {
            $query->where('id', '<', $before);
        }

        // 取倒序的一页再翻转，返回统一按时间正序
        return TicketReplyResource::collection($query->limit($perPage)->get()->reverse()->values());
    }

    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        $actor = $request->user();
        $isInternal = (bool) ($data['is_internal'] ?? false);

        if ($isInternal && ! $actor->isStaff()) {
            return response()->json(['message' => '只有客服可以添加内部备注'], 403);
        }
        if ($isInternal && $ticket->status === 'closed') {
            return response()->json(['message' => '已关闭的工单不能添加内部备注'], 422);
        }

        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'content' => $data['content'],
            'is_internal' => $isInternal,
        ]);

        $ticket->update(['last_activity_at' => now()]);

        if ($isInternal) {
            // 内部备注视为协作动作：不改变状态、不计首次响应、不通知客户
            $ticket->log('internal_note', $actor->id, mb_strimwidth($data['content'], 0, 60, '…'));
        } else {
            // 客服回复待处理工单时自动流转为处理中；首次响应记录时间
            if ($ticket->status === 'open' && $actor->isStaff()) {
                $old = $ticket->status;
                $ticket->update(['status' => 'in_progress']);
                $ticket->log('status_changed', $actor->id,
                    '状态：'.(Ticket::STATUS_LABELS[$old] ?? $old).' → 处理中（客服回复自动流转）');
            }
            if ($actor->isStaff() && $ticket->first_response_at === null) {
                $ticket->update(['first_response_at' => now()]);
            }
            $ticket->log('replied', $actor->id, mb_strimwidth($data['content'], 0, 60, '…'));
        }

        // 通知：内部备注只发给受理客服；公开回复按原有规则通知
        $preview = mb_strimwidth($data['content'], 0, 40, '…');
        if ($isInternal) {
            if ($ticket->agent_id && $ticket->agent_id !== $actor->id) {
                $ticket->agent?->notify(new TicketEventNotification($ticket, "{$actor->name} 在工单 {$ticket->no} 添加了内部备注：{$preview}", 'internal_note'));
            }
        } elseif ($actor->isStaff()) {
            if ($ticket->user_id !== $actor->id) {
                $ticket->user->notify(new TicketEventNotification($ticket, "{$actor->name} 回复了您的工单 {$ticket->no}：{$preview}", 'replied'));
            }
        } else {
            $recipients = $ticket->agent_id
                ? User::where('id', $ticket->agent_id)->get()
                : User::staff()->get();
            Notification::send($recipients, new TicketEventNotification($ticket, "{$actor->name} 回复了工单 {$ticket->no}：{$preview}", 'replied'));
        }

        // @提及：仅客服/管理员可用；普通用户输入的 @ 不解析
        $mentioned = $actor->isStaff()
            ? $this->extractMentions($data['content'])->reject(fn ($u) => $u->id === $actor->id)
            : collect();
        if ($mentioned->isNotEmpty()) {
            Notification::send($mentioned, new TicketEventNotification(
                $ticket, "{$actor->name} 在工单 {$ticket->no} 中提到了你：{$preview}", 'mentioned'
            ));
        }

        // 回复本体也作为即时消息推送给工单参与者（内部备注不推给客户）
        $replyPayload = [
            'type' => 'reply',
            'ticket_id' => $ticket->id,
            'reply' => [
                'id' => $reply->id,
                'content' => $reply->content,
                'is_internal' => $reply->is_internal,
                'created_at' => $reply->created_at->format('Y-m-d H:i'),
                'user' => [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'role' => $actor->role,
                ],
            ],
        ];
        $participants = collect([$ticket->user_id, $ticket->agent_id])
            ->filter()
            ->reject(fn ($id) => $id === $actor->id);
        if ($isInternal) {
            $participants = $participants->filter(fn ($id) => User::whereKey($id)->staff()->exists());
        }
        GatewayPush::toUsers($participants->all(), $replyPayload);

        return response()->json(new TicketReplyResource($reply->load('user:id,name,role')), 201);
    }

    /** 解析内容中的 @提及（支持 @账号 与 @昵称，后随标点亦可） */
    private function extractMentions(string $content): \Illuminate\Support\Collection
    {
        preg_match_all('/@([\x{4e00}-\x{9fa5}a-zA-Z0-9_]+)/u', $content, $matches);
        if (empty($matches[1])) {
            return collect();
        }

        $names = array_unique($matches[1]);

        return User::whereIn('username', $names)
            ->orWhereIn('name', $names)
            ->get()
            ->unique('id')
            ->values();
    }
}
