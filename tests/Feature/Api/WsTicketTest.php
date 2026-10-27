<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class WsTicketTest extends ApiTestCase
{
    public function test_guest_cannot_get_ticket(): void
    {
        $this->postJson('/api/ws-ticket')->assertStatus(401);
    }

    public function test_authenticated_user_gets_one_time_ticket(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $res = $this->actingAs($user)->postJson('/api/ws-ticket')->assertOk();
        $res->assertJsonPath('expires_in', 60);

        $ticket = $res->json('ticket');
        $this->assertIsString($ticket);
        $this->assertGreaterThanOrEqual(40, strlen($ticket));

        // 数据库只存哈希，明文不落库
        $row = DB::table('ws_tickets')->where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $ticket), $row->ticket_hash);
        $this->assertNotSame($ticket, $row->ticket_hash);
        $this->assertNull($row->used_at);
        $this->assertTrue(now()->diffInSeconds($row->expires_at, true) <= 60);
    }

    public function test_inactive_user_cannot_get_ticket(): void
    {
        $user = User::factory()->create(['role' => 'agent', 'is_active' => false]);

        $this->actingAs($user)->postJson('/api/ws-ticket')->assertStatus(403);
    }

    public function test_each_call_issues_a_new_ticket(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $first = $this->actingAs($user)->postJson('/api/ws-ticket')->json('ticket');
        $second = $this->actingAs($user)->postJson('/api/ws-ticket')->json('ticket');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, DB::table('ws_tickets')->where('user_id', $user->id)->count());
    }
}
