<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use App\Support\Settings;
use App\Support\Sla;
use Carbon\CarbonImmutable;
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

    public function test_ticket_gets_resolution_due_at_on_create_and_recalculates_on_priority_change(): void
    {
        Settings::put(['resolution_hours' => ['urgent' => 4, 'high' => 8, 'medium' => 24, 'low' => 48]]);

        $owner = $this->makeUser();
        $res = $this->actingAsApi($owner)->postJson('/api/tickets', [
            'title' => '解决时限测试',
            'content' => '内容',
            'priority' => 'medium',
            'category_id' => \App\Models\Category::firstOrCreate(['name' => '测试分类'])->id,
        ])->assertCreated();

        $ticket = Ticket::find($res->json('id'));
        $this->assertNotNull($ticket->resolution_due_at);
        $this->assertSame(24 * 60, (int) $ticket->created_at->diffInMinutes($ticket->resolution_due_at));

        $this->actingAsApi($this->makeUser('agent'))->putJson("/api/tickets/{$ticket->id}", [
            'priority' => 'high',
        ])->assertOk();

        $ticket->refresh();
        $this->assertSame(8 * 60, (int) $ticket->created_at->diffInMinutes($ticket->resolution_due_at));
    }

    public function test_business_hours_skip_nights_and_weekends(): void
    {
        Settings::put([
            'resolution_hours' => ['urgent' => 2, 'high' => 8, 'medium' => 24, 'low' => 48],
            'business_hours_enabled' => true,
            'business_hours_start' => '09:00',
            'business_hours_end' => '18:00',
            'business_hours_days' => [1, 2, 3, 4, 5],
            'business_timezone' => 'Asia/Shanghai',
        ]);

        // 2026-10-30 是周五，17:00 起算 2 个营业小时：周五 17-18 用掉 1 小时，剩下 1 小时顺延到周一 09-10
        $from = CarbonImmutable::parse('2026-10-30 17:00:00', 'Asia/Shanghai');
        $due = Sla::resolutionDueAt($from, 'urgent');
        $this->assertSame('2026-11-02 10:00', $due->setTimezone('Asia/Shanghai')->format('Y-m-d H:i'));
    }
}
