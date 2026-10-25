<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TicketTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_user_can_create_ticket_and_sla_is_set_by_priority(): void
    {
        $user = $this->makeUser();

        $res = $this->actingAsApi($user)->postJson('/api/tickets', [
            'title' => '无法登录',
            'content' => '一直提示密码错误',
            'priority' => 'urgent',
        ]);

        $res->assertCreated();
        $ticket = Ticket::latest('id')->first();
        $this->assertSame('open', $ticket->status);
        $this->assertStringStartsWith('TK-', $ticket->no);
        // 紧急工单 SLA = 4 小时
        $this->assertEqualsWithDelta(
            now()->addHours(4)->timestamp,
            $ticket->sla_due_at->timestamp, 60
        );
        $this->assertDatabaseHas('ticket_logs', [
            'ticket_id' => $ticket->id, 'action' => 'created',
        ]);
    }

    public function test_create_validates_required_fields_and_priority_enum(): void
    {
        $user = $this->makeUser();

        $this->actingAsApi($user)->postJson('/api/tickets', [
            'title' => '', 'content' => '', 'priority' => 'whatever',
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'content', 'priority']);
    }

    public function test_user_sees_only_own_tickets_while_agent_sees_all(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $agent = $this->makeUser('agent');

        Ticket::factory()->count(2)->create(['user_id' => $owner->id]);
        Ticket::factory()->create(['user_id' => $other->id]);

        $this->actingAsApi($owner)->getJson('/api/tickets')
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->actingAsApi($agent)->getJson('/api/tickets')
            ->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_user_cannot_view_others_ticket_but_agent_can(): void
    {
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);

        $this->actingAsApi($this->makeUser())
            ->getJson("/api/tickets/{$ticket->id}")->assertStatus(403);

        $this->actingAsApi($this->makeUser('agent'))
            ->getJson("/api/tickets/{$ticket->id}")->assertOk();
    }

    public function test_staff_update_sets_timestamps_and_logs(): void
    {
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);

        $this->actingAsApi($agent)->putJson("/api/tickets/{$ticket->id}", [
            'status' => 'pending_resolved',
            'priority' => 'high',
        ])->assertOk();

        $ticket->refresh();
        $this->assertSame('pending_resolved', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $ticket->id, 'action' => 'status_changed']);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $ticket->id, 'action' => 'priority_changed']);
    }

    public function test_close_sets_closed_at(): void
    {
        $admin = $this->makeUser('admin');
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);

        $this->actingAsApi($admin)->putJson("/api/tickets/{$ticket->id}", ['status' => 'closed'])
            ->assertOk();

        $this->assertNotNull($ticket->fresh()->closed_at);
    }

    public function test_normal_user_can_only_edit_own_open_ticket(): void
    {
        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'status' => 'open']);

        // 本人可改标题内容
        $this->actingAsApi($owner)->putJson("/api/tickets/{$ticket->id}", [
            'title' => '改后的标题', 'content' => '新内容',
        ])->assertOk()->assertJsonPath('title', '改后的标题');

        // 改状态被忽略/拒绝（普通用户不在合法字段内且 update 只允许 title/content）
        $this->actingAsApi($owner)->putJson("/api/tickets/{$ticket->id}", [
            'title' => 'x', 'content' => 'y', 'status' => 'closed',
        ])->assertOk();
        $this->assertNotSame('closed', $ticket->fresh()->status);

        // 非本人 403
        $this->actingAsApi($this->makeUser())->putJson("/api/tickets/{$ticket->id}", [
            'title' => 'hack', 'content' => 'hack',
        ])->assertStatus(403);

        // 已进入处理的工单本人不可再编辑
        $ticket->update(['status' => 'in_progress']);
        $this->actingAsApi($owner)->putJson("/api/tickets/{$ticket->id}", [
            'title' => 'late', 'content' => 'late',
        ])->assertStatus(403);
    }

    public function test_only_admin_can_delete_ticket(): void
    {
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);

        $this->actingAsApi($this->makeUser('agent'))
            ->deleteJson("/api/tickets/{$ticket->id}")->assertStatus(403);

        $this->actingAsApi($this->makeUser('admin'))
            ->deleteJson("/api/tickets/{$ticket->id}")->assertOk();

        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
    }

    public function test_mine_filter_uses_current_user_id(): void
    {
        // 回归：mine=1 曾被直接当作 agent_id 使用
        $agent = $this->makeUser('agent'); // id=1 的陷阱场景
        $otherAgent = $this->makeUser('agent');
        Ticket::factory()->count(2)->create(['agent_id' => $agent->id]);
        Ticket::factory()->create(['agent_id' => $otherAgent->id]);

        $this->actingAsApi($otherAgent)->getJson('/api/tickets?mine=1')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_filters_and_pagination(): void
    {
        $user = $this->makeUser();
        $cat = Category::create(['name' => '网络问题']);
        Ticket::factory()->create(['user_id' => $user->id, 'status' => 'open', 'title' => 'VPN 断了']);
        Ticket::factory()->create(['user_id' => $user->id, 'status' => 'closed', 'title' => '打印机坏了', 'category_id' => $cat->id]);

        $this->actingAsApi($user)->getJson('/api/tickets?status=open')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAsApi($user)->getJson('/api/tickets?keyword=VPN')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAsApi($user)->getJson("/api/tickets?category_id={$cat->id}")
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_rating_rules(): void
    {
        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'status' => 'in_progress']);

        // 未关闭不能评价
        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/rating", ['rating' => 5])
            ->assertStatus(422);

        $ticket->update(['status' => 'closed']);
        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/rating", [
            'rating' => 4, 'rating_comment' => '不错',
        ])->assertOk()->assertJsonPath('rating', 4);

        // 只能评一次；非本人不能评
        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/rating", ['rating' => 5])
            ->assertStatus(422);
        $this->actingAsApi($this->makeUser())->postJson("/api/tickets/{$ticket->id}/rating", ['rating' => 1])
            ->assertStatus(403);
    }
}
