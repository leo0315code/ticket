<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

class PresenceTest extends ApiTestCase
{
    private function seedCategory(): int
    {
        return \App\Models\Category::firstOrCreate(['name' => '值守测试'])->id;
    }

    protected function tearDown(): void
    {
        Settings::put([
            'business_hours_enabled' => false,
            'require_online_agent' => false,
            'support_phone' => '',
        ]);
        parent::tearDown();
    }

    public function test_authenticated_request_updates_last_seen_at_throttled(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->last_seen_at);

        $this->actingAsApi($user)->getJson('/api/notifications')->assertOk();
        $this->assertNotNull($user->fresh()->last_seen_at);

        // 60 秒内第二个请求不再写库
        $first = $user->fresh()->last_seen_at;
        $this->travel(10)->seconds();
        $this->actingAsApi($user->fresh())->getJson('/api/notifications')->assertOk();
        $this->assertEquals($first->timestamp, $user->fresh()->last_seen_at->timestamp);
    }

    public function test_online_staff_scope(): void
    {
        $online = $this->makeUser('agent', ['ws_connected_at' => now()]);
        $offline = $this->makeUser('agent', ['ws_connected_at' => now()->subMinutes(30)]);
        $this->makeUser('agent', ['ws_connected_at' => null]);
        $this->makeUser('user', ['ws_connected_at' => now()]); // 普通用户不算

        $ids = User::onlineStaff()->pluck('id');
        $this->assertTrue($ids->contains($online->id));
        $this->assertFalse($ids->contains($offline->id));
    }

    public function test_submit_blocked_outside_work_hours_with_phone(): void
    {
        Settings::put([
            'business_hours_enabled' => true,
            'business_hours_start' => '09:00',
            'business_hours_end' => '18:00',
            'business_hours_days' => [1, 2, 3, 4, 5, 6, 7],
            'support_phone' => '400-800-1234',
        ]);
        // 把当前时间挪到服务时间外（22 点）
        $this->travelTo(now()->setTime(22, 0));

        $user = $this->makeUser();
        $response = $this->actingAsApi($user)->postJson('/api/tickets', [
            'title' => '测试', 'content' => '内容', 'priority' => 'medium', 'category_id' => $this->seedCategory(),
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('400-800-1234', $response->json('message'));
    }

    public function test_submit_blocked_when_no_agent_online(): void
    {
        Settings::put(['require_online_agent' => true]);
        $this->makeUser('agent', ['ws_connected_at' => now()->subHour()]); // 离线客服

        $response = $this->actingAsApi($this->makeUser())->postJson('/api/tickets', [
            'title' => '测试', 'content' => '内容', 'priority' => 'medium', 'category_id' => $this->seedCategory(),
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('暂无客服在线', $response->json('message'));
    }

    public function test_submit_allowed_with_online_agent_and_staff_bypasses(): void
    {
        Settings::put(['require_online_agent' => true]);
        $this->makeUser('agent', ['ws_connected_at' => now()]);

        $this->actingAsApi($this->makeUser())->postJson('/api/tickets', [
            'title' => '测试', 'content' => '内容', 'priority' => 'medium', 'category_id' => $this->seedCategory(),
        ])->assertStatus(201);

        // 即使无人可在线，客服自己也能代录
        Settings::put(['require_online_agent' => true]);
        DB::table('users')->where('role', 'agent')->update(['ws_connected_at' => null]);
        $this->actingAsApi($this->makeUser('admin'))->postJson('/api/tickets', [
            'title' => '代录', 'content' => '内容', 'priority' => 'medium', 'category_id' => $this->seedCategory(),
        ])->assertStatus(201);
    }

    public function test_auto_assign_only_picks_online_staff(): void
    {
        Settings::put(['auto_assign' => true]);
        $category = \App\Models\Category::find($this->seedCategory());
        $offlineDefault = $this->makeUser('agent', ['ws_connected_at' => now()->subHour()]);
        $online = $this->makeUser('agent', ['ws_connected_at' => now()]);
        $category->update(['default_agent_id' => $offlineDefault->id]);

        $response = $this->actingAsApi($this->makeUser())->postJson('/api/tickets', [
            'title' => '测试', 'content' => '内容', 'priority' => 'medium', 'category_id' => $category->id,
        ])->assertStatus(201);

        // 默认客服离线 → 落到在线客服
        $this->assertEquals($online->id, $response->json('data.agent.id') ?? $response->json('agent.id'));
    }

    public function test_meta_exposes_presence_keys(): void
    {
        Settings::put(['support_phone' => '10086', 'require_online_agent' => true]);
        $this->makeUser('agent', ['ws_connected_at' => now()]);

        $meta = $this->getJson('/api/meta')->json();
        $this->assertSame('10086', $meta['support_phone']);
        $this->assertTrue($meta['require_online_agent']);
        $this->assertTrue($meta['agents_online']);
    }
}
