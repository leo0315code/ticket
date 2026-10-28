<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use App\Models\TicketRule;

class TicketRuleTest extends ApiTestCase
{
    public function test_matching_rule_sets_category_and_priority(): void
    {
        $finance = \App\Models\Category::firstOrCreate(['name' => '财务问题']);
        TicketRule::create([
            'name' => '发票类', 'keywords' => '发票,报销',
            'set_category_id' => $finance->id, 'set_priority' => 'high',
        ]);

        $response = $this->actingAsApi($this->makeUser())->postJson('/api/tickets', [
            'title' => '我要开发票', 'content' => '上个月的发票还没收到',
            'priority' => 'low', 'category_id' => \App\Models\Category::firstOrCreate(['name' => '默认分类'])->id,
        ])->assertCreated();

        $ticket = Ticket::findOrFail($response->json('id'));
        $this->assertSame($finance->id, $ticket->category_id);
        $this->assertSame('high', $ticket->priority);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $ticket->id, 'action' => 'rule_applied']);
    }

    public function test_first_match_wins_and_inactive_rules_skipped(): void
    {
        TicketRule::create(['name' => '停用规则', 'keywords' => '发票', 'set_priority' => 'urgent', 'is_active' => false, 'sort' => 0]);
        TicketRule::create(['name' => '低优先级规则', 'keywords' => '发票', 'set_priority' => 'high', 'sort' => 1]);

        $response = $this->actingAsApi($this->makeUser())->postJson('/api/tickets', [
            'title' => '发票问题', 'content' => '内容',
            'priority' => 'medium', 'category_id' => \App\Models\Category::firstOrCreate(['name' => '默认分类'])->id,
        ])->assertCreated();

        $this->assertSame('high', Ticket::findOrFail($response->json('id'))->priority);
    }

    public function test_no_match_leaves_ticket_untouched(): void
    {
        TicketRule::create(['name' => '发票类', 'keywords' => '发票', 'set_priority' => 'urgent']);

        $response = $this->actingAsApi($this->makeUser())->postJson('/api/tickets', [
            'title' => '登录不了', 'content' => '提示密码错误',
            'priority' => 'medium', 'category_id' => \App\Models\Category::firstOrCreate(['name' => '默认分类'])->id,
        ])->assertCreated();

        $this->assertSame('medium', Ticket::findOrFail($response->json('id'))->priority);
    }

    public function test_crud_requires_permission(): void
    {
        $this->actingAsApi($this->makeUser())->getJson('/api/ticket-rules')->assertForbidden();
        $this->actingAsApi($this->makeUser())->postJson('/api/ticket-rules', [
            'name' => 'x', 'keywords' => 'y',
        ])->assertForbidden();

        $admin = $this->makeUser('admin');
        $this->actingAsApi($admin)->postJson('/api/ticket-rules', [
            'name' => '发票类', 'keywords' => '发票,报销', 'set_priority' => 'high',
        ])->assertCreated();
        $this->assertDatabaseHas('ticket_rules', ['name' => '发票类']);

        $id = TicketRule::where('name', '发票类')->value('id');
        $this->actingAsApi($admin)->putJson("/api/ticket-rules/{$id}", [
            'name' => '发票类', 'keywords' => '发票', 'is_active' => false,
        ])->assertOk();
        $this->actingAsApi($admin)->deleteJson("/api/ticket-rules/{$id}")->assertOk();
        $this->assertDatabaseMissing('ticket_rules', ['id' => $id]);
    }
}
