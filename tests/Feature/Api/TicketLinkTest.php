<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TicketLinkTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_linking_is_bidirectional_and_shows_in_detail(): void
    {
        $agent = $this->makeUser('agent');
        $a = Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'no' => 'TK-20261027-1001']);
        $b = Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'no' => 'TK-20261027-1002']);

        $this->actingAsApi($agent)->postJson("/api/tickets/{$a->id}/links", ['target_no' => $b->no])
            ->assertOk()->assertJsonPath('related.0.no', $b->no);

        // 反向：从 B 的详情也能看到 A
        $res = $this->actingAsApi($agent)->getJson("/api/tickets/{$b->id}")->assertOk();
        $this->assertSame([$a->id], array_column($res->json('related'), 'id'));

        $this->assertDatabaseHas('ticket_links', ['ticket_id' => $a->id, 'related_ticket_id' => $b->id]);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $a->id, 'action' => 'linked']);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $b->id, 'action' => 'linked']);
    }

    public function test_duplicate_and_self_link_are_rejected(): void
    {
        $agent = $this->makeUser('agent');
        $a = Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'no' => 'TK-20261027-2001']);
        $b = Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'no' => 'TK-20261027-2002']);

        $this->actingAsApi($agent)->postJson("/api/tickets/{$a->id}/links", ['target_no' => $a->no])->assertStatus(422);
        $this->actingAsApi($agent)->postJson("/api/tickets/{$a->id}/links", ['target_no' => $b->no])->assertOk();
        $this->actingAsApi($agent)->postJson("/api/tickets/{$a->id}/links", ['target_no' => $b->no])
            ->assertStatus(422);
        $this->actingAsApi($agent)->postJson("/api/tickets/{$b->id}/links", ['target_no' => $a->no])
            ->assertStatus(422); // 反向重复同样拦截
    }

    public function test_unlink_removes_both_directions(): void
    {
        $agent = $this->makeUser('agent');
        $a = Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'no' => 'TK-20261027-3001']);
        $b = Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'no' => 'TK-20261027-3002']);

        $this->actingAsApi($agent)->postJson("/api/tickets/{$a->id}/links", ['target_no' => $b->no]);
        $this->actingAsApi($agent)->deleteJson("/api/tickets/{$a->id}/links/{$b->id}")->assertOk();

        $this->assertDatabaseMissing('ticket_links', ['ticket_id' => $a->id, 'related_ticket_id' => $b->id]);
        $res = $this->actingAsApi($agent)->getJson("/api/tickets/{$b->id}");
        $this->assertSame([], $res->json('related'));
    }

    public function test_customer_cannot_link(): void
    {
        $user = $this->makeUser();
        $a = Ticket::factory()->create(['user_id' => $user->id]);
        $b = Ticket::factory()->create(['user_id' => $user->id]);

        $this->actingAsApi($user)->postJson("/api/tickets/{$a->id}/links", ['target_no' => $b->no])->assertStatus(403);
    }
}
