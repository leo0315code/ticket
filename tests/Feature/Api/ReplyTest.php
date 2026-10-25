<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\TicketEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

class ReplyTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_agent_reply_auto_transitions_open_ticket_and_records_first_response(): void
    {
        Notification::fake();
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'status' => 'open']);

        $this->actingAsApi($agent)->postJson("/api/tickets/{$ticket->id}/replies", [
            'content' => '收到，正在处理',
        ])->assertCreated();

        $ticket->refresh();
        $this->assertSame('in_progress', $ticket->status);
        $this->assertNotNull($ticket->first_response_at);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $ticket->id, 'action' => 'replied']);
    }

    public function test_owner_is_notified_of_agent_reply(): void
    {
        Notification::fake();
        $owner = $this->makeUser();
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);

        $this->actingAsApi($agent)->postJson("/api/tickets/{$ticket->id}/replies", [
            'content' => '您好，已收到工单',
        ])->assertCreated();

        Notification::assertSentTo($owner, TicketEventNotification::class, function ($n) use ($agent) {
            return str_contains($n->message, $agent->name) && $n->event === 'replied';
        });
    }

    public function test_user_reply_notifies_assigned_agent_only(): void
    {
        Notification::fake();
        $owner = $this->makeUser();
        $agent = $this->makeUser('agent');
        $otherAgent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'agent_id' => $agent->id]);

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/replies", [
            'content' => '补充信息',
        ])->assertCreated();

        Notification::assertSentTo($agent, TicketEventNotification::class);
        Notification::assertNotSentTo($otherAgent, TicketEventNotification::class);
    }

    public function test_mention_works_by_username_and_by_name(): void
    {
        Notification::fake();
        $owner = $this->makeUser();
        $agent = $this->makeUser('agent');
        // 用账号 @ 提及
        $byUsername = $this->makeUser('agent', ['username' => 'wangwu', 'name' => '小王']);
        $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'agent_id' => $agent->id]);

        // 提及仅 staff 可用：由 agent 发起
        $this->actingAsApi($agent)->postJson("/api/tickets/{$ticket->id}/replies", [
            'content' => '@wangwu 请帮我看下这个问题',
        ])->assertCreated();

        Notification::assertSentTo($byUsername, TicketEventNotification::class, fn ($n) => $n->event === 'mentioned');

        // 用昵称 @ 提及也兼容
        Notification::fake();
        $this->actingAsApi($agent)->postJson("/api/tickets/{$ticket->id}/replies", [
            'content' => '@小王 再看一下',
        ])->assertCreated();
        Notification::assertSentTo($byUsername, TicketEventNotification::class, fn ($n) => $n->event === 'mentioned');
    }

    public function test_normal_user_mention_is_ignored(): void
    {
        Notification::fake();
        $owner = $this->makeUser();
        $mentioned = $this->makeUser('agent', ['username' => 'wangwu', 'name' => '小王']);
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/replies", [
            'content' => '@wangwu 普通用户输入的 @ 不应触发提及通知',
        ])->assertCreated();

        Notification::assertNotSentTo($mentioned, TicketEventNotification::class, fn ($n) => $n->event === 'mentioned');
    }

    public function test_non_participant_cannot_reply(): void
    {
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);

        $this->actingAsApi($this->makeUser())
            ->postJson("/api/tickets/{$ticket->id}/replies", ['content' => '围观'])
            ->assertStatus(403);
    }

    public function test_reply_requires_content(): void
    {
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);

        $this->actingAsApi($this->makeUser('agent'))
            ->postJson("/api/tickets/{$ticket->id}/replies", ['content' => ''])
            ->assertStatus(422);
    }
}
