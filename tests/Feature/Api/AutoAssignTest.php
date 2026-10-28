<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AutoAssignTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_auto_assign_prefers_category_default_agent(): void
    {
        Settings::put(['auto_assign' => true]);

        $agentA = $this->makeUser('agent');
        $agentB = $this->makeUser('agent');
        $category = Category::create(['name' => '支付问题', 'default_agent_id' => $agentB->id]);
        $customer = $this->makeUser();

        $this->actingAsApi($customer)->postJson('/api/tickets', [
            'title' => '扣款重复', 'content' => '被扣了两次', 'priority' => 'medium', 'category_id' => $category->id,
        ])->assertCreated()->assertJsonPath('agent.id', $agentB->id);

        $this->assertDatabaseHas('tickets', ['category_id' => $category->id, 'agent_id' => $agentB->id]);
        $this->assertDatabaseHas('ticket_logs', ['action' => 'assigned']);
    }

    public function test_auto_assign_falls_back_to_least_busy_agent(): void
    {
        Settings::put(['auto_assign' => true]);

        $busy = $this->makeUser('agent');
        $idle = $this->makeUser('agent');
        $category = Category::create(['name' => '售后']);

        Ticket::factory()->count(3)->create([
            'user_id' => $this->makeUser()->id, 'agent_id' => $busy->id, 'status' => 'in_progress',
        ]);

        $this->actingAsApi($this->makeUser())->postJson('/api/tickets', [
            'title' => '标题', 'content' => '内容', 'priority' => 'medium', 'category_id' => $category->id,
        ])->assertCreated()->assertJsonPath('agent.id', $idle->id);
    }

    public function test_auto_assign_off_leaves_ticket_unassigned(): void
    {
        $category = Category::create(['name' => '咨询']);
        $customer = $this->makeUser();

        $this->actingAsApi($customer)->postJson('/api/tickets', [
            'title' => '标题', 'content' => '内容', 'priority' => 'medium', 'category_id' => $category->id,
        ])->assertCreated()->assertJsonPath('agent', null);
    }

    public function test_disabled_default_agent_is_skipped(): void
    {
        Settings::put(['auto_assign' => true]);

        $disabled = $this->makeUser('agent');
        $disabled->update(['is_active' => false]);
        $other = $this->makeUser('agent');
        $category = Category::create(['name' => '物流', 'default_agent_id' => $disabled->id]);

        $this->actingAsApi($this->makeUser())->postJson('/api/tickets', [
            'title' => '标题', 'content' => '内容', 'priority' => 'medium', 'category_id' => $category->id,
        ])->assertCreated()->assertJsonPath('agent.id', $other->id);
    }
}
