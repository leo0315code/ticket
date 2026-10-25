<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

class SlaTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_overdue_filter_and_flag(): void
    {
        $agent = $this->makeUser('agent');
        $overdue = Ticket::factory()->create([
            'user_id' => $this->makeUser()->id,
            'status' => 'open',
            'sla_due_at' => now()->subHours(2),
        ]);
        Ticket::factory()->create([
            'user_id' => $this->makeUser()->id,
            'status' => 'open',
            'sla_due_at' => now()->addHours(2),
        ]);

        $this->actingAsApi($agent)->getJson('/api/tickets?overdue=1')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertTrue($overdue->isOverdue());
    }

    public function test_closed_tickets_are_never_overdue(): void
    {
        $ticket = Ticket::factory()->create([
            'user_id' => $this->makeUser()->id,
            'status' => 'closed',
            'sla_due_at' => now()->subDay(),
        ]);

        $this->assertFalse($ticket->isOverdue());
    }

    public function test_check_sla_command_notifies_once_per_ticket(): void
    {
        Notification::fake();
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create([
            'user_id' => $this->makeUser()->id,
            'agent_id' => $agent->id,
            'status' => 'in_progress',
            'sla_due_at' => now()->subHours(3),
        ]);

        $this->artisan('tickets:check-sla')->expectsOutputToContain('SLA 超时')->assertSuccessful();

        Notification::assertSentTo($agent, TicketEventNotification::class, fn ($n) => $n->event === 'sla_breach');
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $ticket->id, 'action' => 'sla_breach']);

        // 第二次运行不再重复提醒
        Notification::fake();
        $this->artisan('tickets:check-sla')->expectsOutputToContain('没有超时工单')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_resolved_tickets_are_not_flagged(): void
    {
        Notification::fake();
        Ticket::factory()->create([
            'user_id' => $this->makeUser()->id,
            'status' => 'pending_resolved',
            'sla_due_at' => now()->subDay(),
        ]);

        $this->artisan('tickets:check-sla')->expectsOutputToContain('没有超时工单')->assertSuccessful();
        Notification::assertNothingSent();
    }
}
