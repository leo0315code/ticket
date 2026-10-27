<?php

namespace Tests\Feature\Api;

use App\Models\Role;

class RoleTest extends ApiTestCase
{
    public function test_role_list_requires_permission_and_includes_catalog(): void
    {
        $this->actingAsApi($this->makeUser('agent'))
            ->getJson('/api/roles')
            ->assertForbidden();

        $res = $this->actingAsApi($this->makeUser('admin'))
            ->getJson('/api/roles')
            ->assertOk();

        $names = collect($res->json('data'))->pluck('name');
        $this->assertTrue($names->contains('admin'));
        $this->assertTrue($names->contains('agent'));
        $keys = collect($res->json('catalog'))->flatMap(fn ($g) => collect($g['permissions'])->pluck('key'));
        $this->assertTrue($keys->contains('panel.access'));
        $this->assertTrue($keys->contains('ticket.assign'));
    }

    public function test_admin_can_create_update_and_delete_custom_role(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $res = $this->postJson('/api/roles', [
            'name' => 'support',
            'label' => '售后客服',
            'permissions' => ['panel.access', 'stats.view'],
        ])->assertCreated();

        $id = $res->json('id');
        $this->assertSame(2, $res->json('permission_count'));
        $this->assertFalse($res->json('is_system'));

        $this->putJson("/api/roles/{$id}", [
            'label' => '售后',
            'permissions' => ['panel.access', 'stats.view', 'log.view'],
        ])->assertOk()->assertJsonPath('label', '售后')->assertJsonPath('permission_count', 3);

        $this->deleteJson("/api/roles/{$id}")->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $id]);
    }

    public function test_name_must_be_slug_and_permissions_must_be_known(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $this->postJson('/api/roles', ['name' => 'Bad Name', 'label' => 'x', 'permissions' => []])
            ->assertStatus(422);

        $this->postJson('/api/roles', ['name' => 'ok_role', 'label' => 'x', 'permissions' => ['nope']])
            ->assertStatus(422);
    }

    public function test_builtin_roles_are_protected(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $admin = Role::where('name', 'admin')->firstOrFail();
        $this->putJson("/api/roles/{$admin->id}", ['label' => 'x', 'permissions' => []])
            ->assertStatus(422);

        $agent = Role::where('name', 'agent')->firstOrFail();
        $this->deleteJson("/api/roles/{$agent->id}")->assertStatus(422);

        $user = Role::where('name', 'user')->firstOrFail();
        $this->deleteJson("/api/roles/{$user->id}")->assertStatus(422);
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $role = Role::create([
            'name' => 'support',
            'label' => '售后',
            'permissions' => ['panel.access'],
            'is_system' => false,
        ]);
        $this->makeUser('support');

        $this->deleteJson("/api/roles/{$role->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', '还有用户使用该角色，请先调整这些用户的角色');
    }

    public function test_custom_role_permissions_are_enforced(): void
    {
        $admin = $this->makeUser('admin');
        $role = Role::create([
            'name' => 'analyst',
            'label' => '数据观察员',
            'permissions' => ['panel.access', 'stats.view'],
            'is_system' => false,
        ]);
        $analyst = $this->makeUser('analyst');

        $this->actingAsApi($analyst);
        $this->getJson('/api/stats')->assertOk();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/logs')->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();

        // 收回 panel.access 后连客服工作台都进不去
        $role->update(['permissions' => ['stats.view']]);
        $this->refreshAuth();
        $this->getJson('/api/agents')->assertForbidden();

        $this->actingAsApi($admin);
        $this->getJson('/api/roles')->assertOk();
    }

    public function test_permission_change_takes_effect_without_relogin(): void
    {
        $agent = $this->makeUser('agent');
        $this->actingAsApi($agent);
        $this->getJson('/api/logs')->assertOk();

        Role::where('name', 'agent')->firstOrFail()->update([
            'permissions' => ['panel.access', 'stats.view'],
        ]);

        // 生产环境每个请求重新解析用户；测试里守卫会缓存，需要丢弃一次
        $this->refreshAuth();
        $this->getJson('/api/logs')->assertForbidden();
        $this->getJson('/api/stats')->assertOk();
    }
}
