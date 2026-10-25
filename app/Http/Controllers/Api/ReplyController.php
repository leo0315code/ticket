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
    public function index(Ticket $ticket): AnonymousResourceCollection
    {
        return \App\Http\Resources\TicketReplyResource::collection(
            $ticket->replies()->with('user:id,name,role')->oldest()->get()
        );
    }

    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'content' => $data['content'],
        ]);

        // 客服回复待处理工单时自动流转为处理中；首次响应记录时间
        if ($ticket->status === 'open' && $request->user()->isStaff()) {
            $old = $ticket->status;
            $ticket->update(['status' => 'in_progress']);
            $ticket->log('status_changed', $request->user()->id,
                '状态：'.(Ticket::STATUS_LABELS[$old] ?? $old).' → 处理中（客服回复自动流转）');
        }
        if ($request->user()->isStaff() && $ticket->first_response_at === null) {
            $ticket->update(['first_response_at' => now()]);
        }
        $ticket->log('replied', $request->user()->id, mb_strimwidth($data['content'], 0, 60, '…'));

        // 通知：客服回复→提交人；用户回复→受理客服（未指派则通知全部客服）
        $actor = $request->user();
        $preview = mb_strimwidth($data['content'], 0, 40, '…');
        if ($actor->isStaff()) {
            if ($ticket->user_id !== $actor->id) {
                $ticket->user->notify(new TicketEventNotification($ticket, "{$actor->name} 回复了您的工单 {$ticket->no}：{$preview}", 'replied'));
            }
        } else {
            $recipients = $ticket->agent_id
                ? User::where('id', $ticket->agent_id)->get()
                : User::whereIn('role', ['agent', 'admin'])->get();
            Notification::send($recipients, new TicketEventNotification($ticket, "{$actor->name} 回复了工单 {$ticket->no}：{$preview}", 'replied'));
        }

        // @提及：优先匹配账号（username），兼容昵称；被提及者收到提醒
        $mentioned = $this->extractMentions($data['content'])->reject(fn ($u) => $u->id === $actor->id);
        if ($mentioned->isNotEmpty()) {
            Notification::send($mentioned, new TicketEventNotification(
                $ticket, "{$actor->name} 在工单 {$ticket->no} 中提到了你：{$preview}", 'mentioned'
            ));
        }

        // 回复本体也作为即时消息推送给工单参与者：
        // 正在浏览该工单的人（客服或提交人）聊天区实时追加气泡，无需刷新
        $replyPayload = [
            'type' => 'reply',
            'ticket_id' => $ticket->id,
            'reply' => [
                'id' => $reply->id,
                'content' => $reply->content,
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
        GatewayPush::toUsers($participants->all(), $replyPayload);

        return response()->json(new \App\Http\Resources\TicketReplyResource($reply->load('user:id,name,role')), 201);
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
