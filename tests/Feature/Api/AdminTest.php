<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Faq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AdminTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_manage_categories(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent');
        $category = Category::create(['name' => '网络问题']);

        $this->actingAsApi($agent)->postJson('/api/categories', ['name' => 'x'])->assertStatus(403);
        $this->actingAsApi($admin)->postJson('/api/categories', ['name' => '账号问题'])
            ->assertCreated()->assertJsonPath('name', '账号问题');

        $this->actingAsApi($admin)->putJson("/api/categories/{$category->id}", ['name' => '网络与连接'])
            ->assertOk()->assertJsonPath('name', '网络与连接');

        $this->actingAsApi($admin)->deleteJson("/api/categories/{$category->id}")->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_any_logged_in_user_can_list_categories(): void
    {
        Category::create(['name' => '账号问题']);
        $this->actingAsApi($this->makeUser())->getJson('/api/categories')
            ->assertOk()->assertJsonCount(1);
    }

    public function test_only_admin_can_manage_faqs(): void
    {
        $admin = $this->makeUser('admin');
        $faq = Faq::create(['question' => '忘记密码？', 'answer' => '重置即可', 'sort' => 1]);

        $this->actingAsApi($this->makeUser())->getJson('/api/faqs')->assertOk()->assertJsonCount(1);

        $this->actingAsApi($this->makeUser('agent'))->postJson('/api/faqs', [
            'question' => 'x', 'answer' => 'y',
        ])->assertStatus(403);

        $this->actingAsApi($admin)->postJson('/api/faqs', [
            'question' => '如何开发票？', 'answer' => '订单页申请', 'sort' => 2,
        ])->assertCreated();

        $this->actingAsApi($admin)->putJson("/api/faqs/{$faq->id}", [
            'question' => '忘记密码怎么办？', 'answer' => '登录页重置',
        ])->assertOk();

        $this->actingAsApi($admin)->deleteJson("/api/faqs/{$faq->id}")->assertOk();
    }

    public function test_admin_can_change_roles_but_not_own(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        $this->actingAsApi($admin)->putJson("/api/users/{$user->id}", ['role' => 'agent'])
            ->assertOk()->assertJsonPath('role', 'agent');

        $this->actingAsApi($admin)->putJson("/api/users/{$admin->id}", ['role' => 'user'])
            ->assertStatus(422);

        $this->actingAsApi($this->makeUser('agent'))->putJson("/api/users/{$user->id}", ['role' => 'admin'])
            ->assertStatus(403);
    }

    public function test_admin_can_create_staff_account_and_it_can_login(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAsApi($admin)->postJson('/api/users', [
            'name' => '客服小王',
            'username' => 'wangwu',
            'password' => 'secret12345',
            'role' => 'agent',
        ])->assertCreated()->assertJsonPath('role', 'agent');

        // 新客服可直接用账号登录
        $this->postJson('/api/login', ['account' => 'wangwu', 'password' => 'secret12345'])
            ->assertOk()->assertJsonPath('user.role', 'agent');

        // 重复账号 422；非 admin 403
        $this->actingAsApi($admin)->postJson('/api/users', [
            'name' => 'x', 'username' => 'wangwu', 'password' => 'secret12345', 'role' => 'agent',
        ])->assertStatus(422)->assertJsonValidationErrors('username');

        $this->actingAsApi($this->makeUser('agent'))->postJson('/api/users', [
            'name' => 'x', 'username' => 'newbie', 'password' => 'secret12345', 'role' => 'agent',
        ])->assertStatus(403);
    }

    public function test_admin_can_reset_password_and_old_tokens_die(): void
    {
        $admin = $this->makeUser('admin');
        $agent = $this->makeUser('agent', ['username' => 'reset_agent']);
        $oldToken = $agent->createToken('old')->plainTextToken;

        $res = $this->actingAsApi($admin)
            ->putJson("/api/users/{$agent->id}/reset-password")
            ->assertOk();
        $newPassword = $res->json('password');
        $this->assertSame(12, strlen($newPassword));

        // 旧 token 失效（先刷新守卫缓存，避免沿用上一请求的已解析用户）
        $this->refreshAuth()->withHeader('Authorization', "Bearer {$oldToken}")
            ->getJson('/api/me')->assertStatus(401);

        // 新密码可登录
        $this->postJson('/api/login', [
            'account' => 'reset_agent', 'password' => $newPassword,
        ])->assertOk();
    }

    public function test_bulk_close_and_assign(): void
    {
        $agent = $this->makeUser('agent', ['username' => 'bulk_agent']);
        $target = $this->makeUser('agent', ['username' => 'target_agent']);
        $t1 = \App\Models\Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'status' => 'open']);
        $t2 = \App\Models\Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'status' => 'in_progress']);
        $closed = \App\Models\Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'status' => 'closed']);

        // 批量关闭：跳过已关闭
        $res = $this->actingAsApi($agent)->postJson('/api/tickets/bulk', [
            'ids' => [$t1->id, $t2->id, $closed->id], 'action' => 'close',
        ])->assertOk();
        $this->assertSame(2, $res->json('count'));
        $this->assertSame('closed', $t1->fresh()->status);
        $this->assertNotNull($t2->fresh()->closed_at);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $t1->id, 'action' => 'status_changed']);

        // 批量指派
        $res2 = $this->actingAsApi($agent)->postJson('/api/tickets/bulk', [
            'ids' => [$t1->id], 'action' => 'assign', 'agent_id' => $target->id,
        ])->assertOk();
        $this->assertSame(1, $res2->json('count'));
        $this->assertSame($target->id, $t1->fresh()->agent_id);

        // 普通用户 403
        $this->actingAsApi($this->makeUser())->postJson('/api/tickets/bulk', [
            'ids' => [$t1->id], 'action' => 'close',
        ])->assertStatus(403);
    }

    public function test_stats_include_by_agent_performance(): void
    {
        $this->actingAsApi($this->makeUser('agent'))->getJson('/api/stats')
            ->assertOk()
            ->assertJsonStructure(['by_agent' => [['id', 'name', 'assigned', 'closed', 'avg_response_min', 'avg_rating']]]);
    }

    public function test_logs_endpoint_filters_and_paginates(): void
    {
        $agent = $this->makeUser('agent');
        $ticket = \App\Models\Ticket::factory()->create(['user_id' => $this->makeUser()->id]);
        $ticket->log('created', $agent->id, '创建工单【测试】');

        $res = $this->actingAsApi($agent)->getJson('/api/logs')->assertOk();
        $this->assertGreaterThanOrEqual(1, $res->json('meta.total'));

        // 按动作筛选
        $res2 = $this->actingAsApi($agent)->getJson('/api/logs?action=created')->assertOk();
        $this->assertSame('created', $res2->json('data.0.action'));

        // 普通用户 403
        $this->actingAsApi($this->makeUser())->getJson('/api/logs')->assertStatus(403);
    }

    public function test_users_list_is_admin_only(): void
    {
        $this->actingAsApi($this->makeUser('agent'))->getJson('/api/users')->assertStatus(403);
        $this->actingAsApi($this->makeUser('admin'))->getJson('/api/users')->assertOk();
    }

    public function test_stats_requires_staff(): void
    {
        $this->actingAsApi($this->makeUser())->getJson('/api/stats')->assertStatus(403);

        $res = $this->actingAsApi($this->makeUser('agent'))->getJson('/api/stats')->assertOk();
        $res->assertJsonStructure(['total', 'unhandled', 'overdue', 'today_new', 'by_status', 'by_priority', 'trend']);
    }

    public function test_stats_mine_counts_current_user_tickets(): void
    {
        $user = $this->makeUser();
        \App\Models\Ticket::factory()->count(2)->create(['user_id' => $user->id, 'status' => 'open']);
        \App\Models\Ticket::factory()->create(['user_id' => $user->id, 'status' => 'closed']);
        \App\Models\Ticket::factory()->create(['user_id' => $this->makeUser()->id]); // 别人的

        $res = $this->actingAsApi($user)->getJson('/api/stats/mine')->assertOk();
        $this->assertSame(3, $res->json('total'));
        $this->assertSame(2, $res->json('by_status.open'));
        $this->assertSame(1, $res->json('by_status.closed'));
    }

    public function test_notifications_index_and_mark_read(): void
    {
        $user = $this->makeUser();
        $user->notify(new \App\Notifications\TicketEventNotification(
            \App\Models\Ticket::factory()->create(['user_id' => $user->id]),
            '测试通知', 'replied'
        ));

        $res = $this->actingAsApi($user)->getJson('/api/notifications')->assertOk();
        $this->assertSame(1, $res->json('unread'));

        $id = $res->json('data.0.id');
        $this->actingAsApi($user)->postJson('/api/notifications/read', ['id' => $id])->assertOk();
        $this->assertSame(0, $this->actingAsApi($user)->getJson('/api/notifications')->json('unread'));
    }
}
