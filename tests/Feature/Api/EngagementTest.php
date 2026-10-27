<?php

namespace Tests\Feature\Api;

use App\Models\CannedResponse;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\TicketEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

class EngagementTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_read_replies_of_other_ticket(): void
    {
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);
        TicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $ticket->user_id, 'content' => '私有内容']);

        $this->actingAsApi($this->makeUser())
            ->getJson("/api/tickets/{$ticket->id}/replies")
            ->assertStatus(403);
    }

    public function test_internal_note_is_only_visible_to_staff(): void
    {
        Notification::fake();
        $owner = $this->makeUser();
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'agent_id' => $agent->id]);

        $this->actingAsApi($agent)->postJson("/api/tickets/{$ticket->id}/replies", [
            'content' => '这个客户上个月也报过同样的问题',
            'is_internal' => true,
        ])->assertCreated()->assertJsonPath('is_internal', true);

        $ticket->refresh();
        $this->assertSame('open', $ticket->status, '内部备注不应流转状态');
        $this->assertNull($ticket->first_response_at, '内部备注不算首次响应');
        Notification::assertNotSentTo($owner, TicketEventNotification::class);

        // 客户侧：详情和列表都读不到
        $this->actingAsApi($owner)->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()->assertJsonCount(0, 'replies');
        $this->actingAsApi($owner)->getJson("/api/tickets/{$ticket->id}/replies")
            ->assertOk()->assertJsonCount(0, 'data');

        // 客服侧：读得到
        $this->actingAsApi($agent)->getJson("/api/tickets/{$ticket->id}/replies")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_internal', true);
    }

    public function test_customer_cannot_write_internal_note(): void
    {
        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/replies", [
            'content' => '假装内部备注',
            'is_internal' => true,
        ])->assertStatus(403);
    }

    public function test_operation_logs_are_hidden_from_customer(): void
    {
        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);
        $ticket->log('assigned', $owner->id, '内部动作');

        $this->actingAsApi($owner)->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()->assertJsonMissingPath('logs');

        $this->actingAsApi($this->makeUser('agent'))->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()->assertJsonCount(1, 'logs');
    }

    public function test_priority_change_recalculates_sla(): void
    {
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create([
            'user_id' => $this->makeUser()->id,
            'priority' => 'medium',
            'created_at' => now()->subHours(2),
        ]);

        $this->actingAsApi($agent)->putJson("/api/tickets/{$ticket->id}", ['priority' => 'urgent'])
            ->assertOk()->assertJsonPath('priority', 'urgent');

        $this->assertTrue(
            $ticket->fresh()->sla_due_at->equalTo($ticket->created_at->copy()->addHours(4)),
            'SLA 截止时间应按新优先级从创建时间重算'
        );
    }

    public function test_reopening_clears_close_timestamps(): void
    {
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);

        $this->actingAsApi($agent)->putJson("/api/tickets/{$ticket->id}", ['status' => 'closed'])->assertOk();
        $this->assertNotNull($ticket->fresh()->closed_at);

        $this->actingAsApi($agent)->putJson("/api/tickets/{$ticket->id}", ['status' => 'open'])->assertOk();

        $fresh = $ticket->fresh();
        $this->assertNull($fresh->closed_at);
        $this->assertNull($fresh->resolved_at);
        $this->assertNull($fresh->closed_reason);
    }

    public function test_owner_can_reopen_closed_ticket(): void
    {
        Notification::fake();
        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create([
            'user_id' => $owner->id,
            'status' => 'closed',
            'closed_at' => now()->subDay(),
            'resolved_at' => now()->subDay(),
            'reopen_count' => 0,
        ]);

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/reopen")
            ->assertOk()->assertJsonPath('status', 'open');

        $fresh = $ticket->fresh();
        $this->assertNull($fresh->closed_at);
        $this->assertSame(1, $fresh->reopen_count);

        // 非提交人不能重开
        $this->actingAsApi($this->makeUser())->postJson("/api/tickets/{$ticket->id}/reopen")
            ->assertStatus(403);
    }

    public function test_reopen_rejected_after_14_days_or_3_times(): void
    {
        $owner = $this->makeUser();
        $old = Ticket::factory()->create([
            'user_id' => $owner->id, 'status' => 'closed', 'closed_at' => now()->subDays(20),
        ]);
        $this->actingAsApi($owner)->postJson("/api/tickets/{$old->id}/reopen")->assertStatus(422);

        $tired = Ticket::factory()->create([
            'user_id' => $owner->id, 'status' => 'closed', 'closed_at' => now()->subDay(), 'reopen_count' => 3,
        ]);
        $this->actingAsApi($owner)->postJson("/api/tickets/{$tired->id}/reopen")->assertStatus(422);
    }

    public function test_responded_ticket_is_no_longer_overdue(): void
    {
        $ticket = Ticket::factory()->create([
            'user_id' => $this->makeUser()->id,
            'status' => 'in_progress',
            'sla_due_at' => now()->subHours(2),
            'first_response_at' => now()->subHour(),
        ]);

        $this->assertFalse($ticket->isOverdue());

        $this->actingAsApi($this->makeUser('agent'))->getJson('/api/tickets?overdue=1')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_per_page_is_capped(): void
    {
        $agent = $this->makeUser('agent');
        Ticket::factory()->count(3)->create(['user_id' => $this->makeUser()->id]);

        $this->actingAsApi($agent)->getJson('/api/tickets?per_page=5000')
            ->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_canned_responses_crud_and_staff_only(): void
    {
        $agent = $this->makeUser('agent');

        $id = $this->actingAsApi($agent)->postJson('/api/canned-responses', [
            'title' => '已收到',
            'body' => '您好，问题已收到，我们正在处理。',
        ])->assertCreated()->json()['data']['id'];

        $this->actingAsApi($agent)->getJson('/api/canned-responses')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAsApi($agent)->postJson("/api/canned-responses/{$id}/touch")->assertOk();
        $this->assertSame(1, CannedResponse::find($id)->uses);

        $this->actingAsApi($agent)->putJson("/api/canned-responses/{$id}", [
            'title' => '已收到（改）', 'body' => '新的正文',
        ])->assertOk()->assertJsonPath('data.title', '已收到（改）');

        $this->actingAsApi($agent)->deleteJson("/api/canned-responses/{$id}")->assertOk();

        // 普通用户不可用
        $user = $this->makeUser();
        $this->actingAsApi($user)->getJson('/api/canned-responses')->assertStatus(403);
        $this->actingAsApi($user)->postJson('/api/canned-responses', ['title' => 'x', 'body' => 'y'])->assertStatus(403);
    }

    public function test_auto_close_stale_pending_tickets(): void
    {
        $stale = Ticket::factory()->create([
            'user_id' => $this->makeUser()->id,
            'status' => 'pending_resolved',
            'last_activity_at' => now()->subDays(8),
        ]);
        $fresh = Ticket::factory()->create([
            'user_id' => $this->makeUser()->id,
            'status' => 'pending_resolved',
            'last_activity_at' => now()->subDays(2),
        ]);

        $this->artisan('tickets:auto-close')->assertSuccessful();

        $this->assertSame('closed', $stale->fresh()->status);
        $this->assertSame('auto', $stale->fresh()->closed_reason);
        $this->assertSame('pending_resolved', $fresh->fresh()->status);
    }

    public function test_replies_are_paginated_by_cursor_and_detail_returns_latest_page(): void
    {
        $owner = $this->makeUser();
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'agent_id' => $agent->id]);

        $ids = [];
        for ($i = 1; $i <= 35; $i++) {
            $ids[] = TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $i % 2 ? $owner->id : $agent->id,
                'content' => "第 {$i} 条回复",
            ])->id;
        }

        // 详情只返回最新 30 条，并带总数
        $detail = $this->actingAsApi($owner)->getJson("/api/tickets/{$ticket->id}")->assertOk();
        $detail->assertJsonCount(30, 'replies')->assertJsonPath('replies_count', 35);
        $this->assertSame($ids[34], $detail->json('replies.29.id'));

        // 第一页（默认最新 30 条，正序）
        $first = $this->actingAsApi($owner)
            ->getJson("/api/tickets/{$ticket->id}/replies?per_page=30")->assertOk();
        $first->assertJsonCount(30, 'data')
            ->assertJsonPath('data.0.id', $ids[5])
            ->assertJsonPath('data.29.id', $ids[34]);

        // 更早的一页：before_id 游标
        $older = $this->actingAsApi($owner)
            ->getJson("/api/tickets/{$ticket->id}/replies?before_id={$ids[5]}&per_page=30")->assertOk();
        $older->assertJsonCount(5, 'data')->assertJsonPath('data.0.id', $ids[0]);

        // per_page 被夹到上限 100，且不会因新回复插入而错位
        $this->actingAsApi($owner)
            ->getJson("/api/tickets/{$ticket->id}/replies?per_page=500")
            ->assertOk()->assertJsonCount(35, 'data');
    }

    public function test_customer_reply_pagination_skips_internal_notes(): void
    {
        $owner = $this->makeUser();
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'agent_id' => $agent->id]);

        TicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $owner->id, 'content' => '公开 1']);
        TicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $agent->id, 'content' => '内部 1', 'is_internal' => true]);
        TicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $owner->id, 'content' => '公开 2']);

        $this->actingAsApi($owner)->getJson("/api/tickets/{$ticket->id}/replies")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($agent)->getJson("/api/tickets/{$ticket->id}/replies")
            ->assertOk()->assertJsonCount(3, 'data');

        // 客户看到的详情总数也不含内部备注
        $this->actingAsApi($owner)->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()->assertJsonPath('replies_count', 2);
    }
}
