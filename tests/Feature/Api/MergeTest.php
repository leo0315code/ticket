<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use App\Notifications\TicketEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

class MergeTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_staff_merges_ticket_and_replies_move_to_target(): void
    {
        Notification::fake();

        $owner = $this->makeUser();
        $agent = $this->makeUser('agent');
        $source = Ticket::factory()->create(['user_id' => $owner->id, 'no' => 'TK-20261027-0001']);
        $target = Ticket::factory()->create(['user_id' => $owner->id, 'no' => 'TK-20261027-0002']);

        $this->actingAsApi($owner)->postJson("/api/tickets/{$source->id}/replies", ['content' => '原始描述'])->assertCreated();

        $this->actingAsApi($agent)->postJson("/api/tickets/{$source->id}/merge", ['target_no' => $target->no])
            ->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('closed_reason', 'merged')
            ->assertJsonPath('merged_into.no', $target->no);

        $this->assertDatabaseHas('tickets', [
            'id' => $source->id,
            'status' => 'closed',
            'closed_reason' => 'merged',
            'merged_into_id' => $target->id,
        ]);
        $this->assertDatabaseHas('ticket_replies', ['ticket_id' => $target->id, 'content' => '原始描述']);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $source->id, 'action' => 'merged']);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $target->id, 'action' => 'merged_in']);

        Notification::assertSentTo($owner, TicketEventNotification::class, fn ($n) => $n->event === 'merged');
    }

    public function test_cannot_merge_into_self_or_unknown_ticket(): void
    {
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);

        $this->actingAsApi($agent)->postJson("/api/tickets/{$ticket->id}/merge", ['target_no' => $ticket->no])
            ->assertStatus(422);
        $this->actingAsApi($agent)->postJson("/api/tickets/{$ticket->id}/merge", ['target_no' => 'TK-NOPE'])
            ->assertStatus(422);
    }

    public function test_ticket_without_close_permission_cannot_merge(): void
    {
        $user = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $user->id]);
        $target = Ticket::factory()->create(['user_id' => $user->id]);

        $this->actingAsApi($user)->postJson("/api/tickets/{$ticket->id}/merge", ['target_no' => $target->no])
            ->assertStatus(403);
    }
}
