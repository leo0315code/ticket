<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;

class UserManagementTest extends ApiTestCase
{
    public function test_list_supports_search_role_status_and_pagination(): void
    {
        $this->makeUser('agent', ['name' => '客服小王', 'username' => 'xiaowang', 'email' => 'wang@example.com']);
        $this->makeUser('agent', ['name' => '客服小李', 'username' => 'xiaoli']);
        $this->makeUser('user', ['name' => '客户张三', 'username' => 'zhangsan']);
        $this->makeUser('agent', ['name' => '已停用', 'username' => 'gone', 'is_active' => false]);

        $this->actingAsApi($this->makeUser('admin'));

        $res = $this->getJson('/api/users?q=wang')->assertOk();
        $this->assertSame(['xiaowang'], collect($res->json('data'))->pluck('username')->all());

        $res = $this->getJson('/api/users?role=user')->assertOk();
        $this->assertTrue(collect($res->json('data'))->every(fn ($u) => $u['role'] === 'user'));

        $res = $this->getJson('/api/users?is_active=0')->assertOk();
        $this->assertSame(['gone'], collect($res->json('data'))->pluck('username')->all());

        $res = $this->getJson('/api/users?per_page=2&page=1')->assertOk();
        $this->assertCount(2, $res->json('data'));
        $this->assertSame(2, $res->json('meta.per_page'));
        $this->assertGreaterThanOrEqual(5, $res->json('meta.total'));
    }

    public function test_agent_cannot_manage_users(): void
    {
        $this->actingAsApi($this->makeUser('agent'))
            ->getJson('/api/users')
            ->assertForbidden();
    }

    public function test_deactivated_user_cannot_login(): void
    {
        $user = $this->makeUser('agent', ['username' => 'sleepy', 'is_active' => false]);

        $this->postJson('/api/login', ['account' => 'sleepy', 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('message', '账号已停用，请联系管理员');

        $this->assertSame(false, $user->fresh()->is_active);
    }

    public function test_deactivating_kills_existing_tokens(): void
    {
        $target = $this->makeUser('agent');
        $token = $target->createToken('old')->plainTextToken;

        $this->actingAsApi($this->makeUser('admin'))
            ->putJson("/api/users/{$target->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertSame(0, $target->tokens()->count());
        $this->refreshAuth();
        $this->assertSame(401, $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me')->status());
    }

    public function test_inactive_token_is_rejected_by_active_middleware(): void
    {
        $user = $this->makeUser('agent');
        $this->actingAsApi($user);
        $user->forceFill(['is_active' => false])->save();

        $this->getJson('/api/tickets')
            ->assertStatus(403)
            ->assertJsonPath('message', '账号已停用，请联系管理员');
    }

    public function test_cannot_deactivate_or_demote_self_and_last_admin_is_protected(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAsApi($admin);

        $this->putJson("/api/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', '不能停用自己的账号');

        $this->putJson("/api/users/{$admin->id}", ['role' => 'agent'])
            ->assertStatus(422)
            ->assertJsonPath('message', '不能修改自己的角色');

        // 另一个管理员把唯一的管理员降级 → 拒绝
        $other = $this->makeUser('admin');
        $this->actingAsApi($other);
        $this->putJson("/api/users/{$admin->id}", ['role' => 'agent'])->assertOk();
        $this->putJson("/api/users/{$other->id}", ['role' => 'agent'])->assertStatus(422);
    }

    public function test_admin_can_create_user_with_custom_role_and_edit_profile(): void
    {
        Role::create([
            'name' => 'support',
            'label' => '售后',
            'permissions' => ['panel.access', 'stats.view'],
            'is_system' => false,
        ]);

        $this->actingAsApi($this->makeUser('admin'));

        $res = $this->postJson('/api/users', [
            'name' => '售后小赵',
            'username' => 'zhao',
            'password' => 'password123',
            'role' => 'support',
        ])->assertCreated();

        $this->assertSame('support', $res->json('role'));
        $this->assertSame('售后', $res->json('role_label'));

        $id = $res->json('id');
        $this->putJson("/api/users/{$id}", [
            'name' => '售后小赵（改）',
            'email' => 'zhao@example.com',
            'role' => 'agent',
        ])->assertOk()->assertJsonPath('name', '售后小赵（改）')->assertJsonPath('role', 'agent');

        // 重复邮箱不能占用
        $this->makeUser('user', ['email' => 'taken@example.com']);
        $this->putJson("/api/users/{$id}", ['email' => 'taken@example.com'])->assertStatus(422);
    }

    public function test_unknown_role_cannot_be_assigned(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $this->postJson('/api/users', [
            'name' => 'x',
            'username' => 'xx',
            'password' => 'password123',
            'role' => 'not_exists',
        ])->assertStatus(422);
    }
}
