<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Support\Settings;

class MaintenanceModeTest extends ApiTestCase
{
    private function payload(): array
    {
        return [
            'title' => '维护期间提单',
            'content' => '内容内容内容',
            'priority' => 'medium',
            'category_id' => Category::firstOrCreate(['name' => '维护测试'])->id,
        ];
    }

    public function test_off_by_default_writes_allowed(): void
    {
        $this->actingAsApi($this->makeUser())
            ->postJson('/api/tickets', $this->payload())->assertStatus(201);
    }

    public function test_on_blocks_user_writes_but_allows_reads(): void
    {
        Settings::put(['maintenance_mode' => true, 'maintenance_message' => '升级中，10 分钟后恢复']);

        $user = $this->makeUser();
        $this->actingAsApi($user)->postJson('/api/tickets', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('message', '升级中，10 分钟后恢复');

        // 只读：列表、帮助中心、meta 正常
        $this->actingAsApi($user)->getJson('/api/tickets')->assertOk();
        $this->getJson('/api/faqs')->assertOk();
        $this->getJson('/api/meta')
            ->assertOk()
            ->assertJsonPath('maintenance_mode', true)
            ->assertJsonPath('maintenance_message', '升级中，10 分钟后恢复');
    }

    public function test_on_blocks_guest_writes_but_allows_login(): void
    {
        Settings::put(['maintenance_mode' => true]);

        // 自助注册本就已关闭；用公开写接口（FAQ 反馈）验证游客写操作被拦
        $faq = \App\Models\Faq::create(['question' => '如何重置密码？', 'answer' => '在登录页点忘记密码。', 'is_public' => true]);
        $this->postJson("/api/faqs/{$faq->id}/feedback", ['useful' => true])->assertStatus(503);

        // 登录必须放行，否则管理员进不来（工厂不生成 username，需显式给）
        $admin = $this->makeUser('admin');
        $admin->forceFill(['username' => 'maint_admin'])->save();
        $this->postJson('/api/login', ['account' => 'maint_admin', 'password' => 'password'])
            ->assertOk();
    }

    public function test_admin_writes_not_affected(): void
    {
        Settings::put(['maintenance_mode' => true]);

        $this->actingAsApi($this->makeUser('admin'))
            ->postJson('/api/tickets', $this->payload())->assertStatus(201);
    }

    public function test_off_restores_writes(): void
    {
        Settings::put(['maintenance_mode' => true]);
        Settings::put(['maintenance_mode' => false]);

        $this->actingAsApi($this->makeUser())
            ->postJson('/api/tickets', $this->payload())->assertStatus(201);
    }
}
