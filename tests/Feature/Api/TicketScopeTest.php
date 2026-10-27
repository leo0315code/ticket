<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;

class TicketScopeTest extends ApiTestCase
{
    private function scopeAgent(string $scope): User
    {
        Role::where('name', 'agent')->firstOrFail()->update(['ticket_scope' => $scope]);

        return $this->makeUser('agent');
    }

    public function test_assigned_scope_only_sees_own_assigned_tickets(): void
    {
        $agent = $this->scopeAgent('assigned');
        $other = $this->makeUser('agent');
        $owner = $this->makeUser();

        $mine = Ticket::factory()->create(['user_id' => $owner->id, 'agent_id' => $agent->id, 'title' => '指派给我的']);
        $unassigned = Ticket::factory()->create(['user_id' => $owner->id, 'agent_id' => null, 'title' => '未指派']);
        $others = Ticket::factory()->create(['user_id' => $owner->id, 'agent_id' => $other->id, 'title' => '别人的']);

        $this->actingAsApi($agent);

        $res = $this->getJson('/api/tickets')->assertOk();
        $this->assertSame(['指派给我的'], collect($res->json('data'))->pluck('title')->all());

        $this->getJson("/api/tickets/{$mine->id}")->assertOk();
        $this->getJson("/api/tickets/{$unassigned->id}")->assertForbidden();
        $this->getJson("/api/tickets/{$others->id}")->assertForbidden();
        $this->getJson("/api/tickets/{$others->id}/replies")->assertForbidden();

        // 导出同样受限
        $csv = $this->get('/api/tickets/export')->streamedContent();
        $this->assertStringContainsString('指派给我的', $csv);
        $this->assertStringNotContainsString('未指派', $csv);
        $this->assertStringNotContainsString('别人的', $csv);
    }

    public function test_own_scope_only_sees_tickets_they_submitted(): void
    {
        $agent = $this->scopeAgent('own');
        $other = $this->makeUser('agent');

        $mine = Ticket::factory()->create(['user_id' => $agent->id, 'title' => '我自己提的']);
        $assigned = Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'agent_id' => $agent->id, 'title' => '指派给我但不是我提的']);

        $this->actingAsApi($agent);

        $res = $this->getJson('/api/tickets')->assertOk();
        $this->assertSame(['我自己提的'], collect($res->json('data'))->pluck('title')->all());

        $this->getJson("/api/tickets/{$mine->id}")->assertOk();
        $this->getJson("/api/tickets/{$assigned->id}")->assertForbidden();
    }

    public function test_all_scope_stays_default_for_agent_and_admin(): void
    {
        $owner = $this->makeUser();
        Ticket::factory()->create(['user_id' => $owner->id]);
        Ticket::factory()->create(['user_id' => $owner->id]);

        $this->actingAsApi($this->makeUser('agent'));
        $this->assertCount(2, $this->getJson('/api/tickets')->json('data'));

        $this->actingAsApi($this->makeUser('admin'));
        $this->assertCount(2, $this->getJson('/api/tickets')->json('data'));
    }

    public function test_scope_change_applies_without_relogin(): void
    {
        $agent = $this->makeUser('agent');
        $owner = $this->makeUser();
        Ticket::factory()->create(['user_id' => $owner->id, 'agent_id' => null]);

        $this->actingAsApi($agent);
        $this->assertCount(1, $this->getJson('/api/tickets')->json('data'));

        Role::where('name', 'agent')->firstOrFail()->update(['ticket_scope' => 'assigned']);
        $this->refreshAuth();
        $this->assertCount(0, $this->getJson('/api/tickets')->json('data'));
    }

    public function test_full_scope_without_view_all_permission_downgrades_to_assigned(): void
    {
        Role::create([
            'name' => 'limited',
            'label' => '受限客服',
            'permissions' => ['panel.access'],
            'ticket_scope' => 'all',
            'is_system' => false,
        ]);
        $agent = $this->makeUser('limited');
        Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'agent_id' => null]);

        $this->actingAsApi($agent);
        $this->assertCount(0, $this->getJson('/api/tickets')->json('data'));
    }

    public function test_normal_user_scope_is_always_own_tickets(): void
    {
        $role = Role::where('name', 'user')->firstOrFail();
        $role->update(['ticket_scope' => 'all']); // 即使配置成全部，普通用户也只能看自己的

        $user = $this->makeUser();
        Ticket::factory()->create(['user_id' => $user->id, 'title' => '我的']);
        Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'title' => '别人的']);

        $this->actingAsApi($user);

        $res = $this->getJson('/api/tickets')->assertOk();
        $this->assertSame(['我的'], collect($res->json('data'))->pluck('title')->all());
    }
}
