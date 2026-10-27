<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use App\Notifications\TicketEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

class WatcherTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_watch_and_unwatch_toggle(): void
    {
        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/watch")
            ->assertOk()->assertJson(['watching' => true, 'count' => 1]);
        $this->assertDatabaseHas('ticket_watchers', ['ticket_id' => $ticket->id, 'user_id' => $owner->id]);

        $this->actingAsApi($owner)->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()->assertJsonPath('is_watching', true)->assertJsonPath('watchers_count', 1);

        $this->actingAsApi($owner)->deleteJson("/api/tickets/{$ticket->id}/watch")
            ->assertOk()->assertJson(['watching' => false, 'count' => 0]);
        $this->assertDatabaseMissing('ticket_watchers', ['ticket_id' => $ticket->id, 'user_id' => $owner->id]);
    }

    public function test_customer_cannot_see_watcher_names_but_staff_can(): void
    {
        $owner = $this->makeUser();
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);
        $ticket->watchers()->attach($agent->id);

        $customer = $this->actingAsApi($owner)->getJson("/api/tickets/{$ticket->id}")->assertOk();
        $this->assertArrayNotHasKey('watchers', $customer->json());

        $staff = $this->actingAsApi($agent)->getJson("/api/tickets/{$ticket->id}")->assertOk();
        $this->assertSame($agent->name, $staff->json('watchers.0.name'));
    }

    public function test_watcher_gets_notified_on_public_reply_and_status_change(): void
    {
        Notification::fake();

        $owner = $this->makeUser();
        $agent = $this->makeUser('agent');
        $watcher = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'agent_id' => $agent->id]);
        $ticket->watchers()->attach([$watcher->id]);

        $this->actingAsApi($agent)->postJson("/api/tickets/{$ticket->id}/replies", [
            'content' => '已经定位到问题，稍后给方案',
        ])->assertCreated();

        Notification::assertSentTo($watcher, TicketEventNotification::class);

        $this->actingAsApi($agent)->putJson("/api/tickets/{$ticket->id}", [
            'status' => 'pending_resolved',
        ])->assertOk();

        Notification::assertSentTo($watcher, TicketEventNotification::class, function ($n) {
            return $n->event === 'watched';
        });
    }
}
