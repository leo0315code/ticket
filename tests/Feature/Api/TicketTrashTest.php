<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Ticket;
use Illuminate\Database\QueryException;

class TicketTrashTest extends ApiTestCase
{
    private function makeTicket($user, array $attrs = []): Ticket
    {
        return Ticket::create([
            'no' => 'TK-TEST-'.uniqid(),
            'title' => '待删除工单',
            'content' => '内容',
            'status' => 'open',
            'priority' => 'medium',
            'user_id' => $user->id,
            'category_id' => Category::firstOrCreate(['name' => '测试'])->id,
            ...$attrs,
        ]);
    }

    public function test_trash_and_restore_require_delete_permission(): void
    {
        $ticket = $this->makeTicket($this->makeUser('user'));
        $ticket->delete();
        $agent = $this->makeUser('agent');

        $this->actingAsApi($agent)->getJson('/api/tickets/trash')->assertForbidden();
        $this->actingAsApi($agent)->postJson("/api/tickets/{$ticket->id}/restore")->assertForbidden();
    }

    public function test_admin_can_list_and_restore_deleted_ticket(): void
    {
        $user = $this->makeUser('user');
        $ticket = $this->makeTicket($user);
        $ticket->delete();

        $admin = $this->makeUser('admin');

        $res = $this->actingAsApi($admin)->getJson('/api/tickets/trash')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertNotNull($res->json('data.0.deleted_at'));

        $this->actingAsApi($admin)->postJson("/api/tickets/{$ticket->id}/restore")->assertOk();

        $this->assertNull(Ticket::find($ticket->id)->deleted_at);
        $this->actingAsApi($admin)->getJson('/api/tickets')->assertOk();
        $this->assertSame(1, Ticket::count());
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $ticket->id, 'action' => 'restored']);
    }

    public function test_trash_supports_keyword_and_pagination(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser('user');
        foreach (range(1, 3) as $i) {
            $t = $this->makeTicket($user, ['title' => "退款问题 {$i}"]);
            $t->delete();
        }
        $other = $this->makeTicket($user, ['title' => '登录异常']);
        $other->delete();

        $res = $this->actingAsApi($admin)
            ->getJson('/api/tickets/trash?keyword='.urlencode('退款').'&per_page=2')
            ->assertOk();

        $this->assertSame(3, $res->json('meta.total'));
        $this->assertCount(2, $res->json('data'));
    }

    public function test_deleting_user_with_tickets_is_blocked_by_foreign_key(): void
    {
        $user = $this->makeUser('user');
        $this->makeTicket($user);

        $this->expectException(QueryException::class);
        $user->delete();
    }

    public function test_notifications_are_paginated(): void
    {
        $user = $this->makeUser('user');
        foreach (range(1, 25) as $i) {
            $user->notifications()->create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'App\\Notifications\\TicketEventNotification',
                'data' => ['message' => "通知 {$i}", 'ticket_id' => 1],
            ]);
        }

        $first = $this->actingAsApi($user)->getJson('/api/notifications')->assertOk();
        $this->assertCount(20, $first->json('data'));
        $this->assertTrue($first->json('has_more'));
        $this->assertSame(25, $first->json('unread'));

        $second = $this->actingAsApi($user)->getJson('/api/notifications?page=2')->assertOk();
        $this->assertCount(5, $second->json('data'));
        $this->assertFalse($second->json('has_more'));
    }

    public function test_logs_per_page_is_capped(): void
    {
        $admin = $this->makeUser('admin');

        $res = $this->actingAsApi($admin)->getJson('/api/logs?per_page=1000')->assertOk();
        $this->assertSame(100, $res->json('meta.per_page'));
    }
}
