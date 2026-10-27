<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 设置是进程内静态缓存，测试之间必须清掉
        Settings::flush();
        // 角色/权限也是进程内静态缓存，RefreshDatabase 重跑迁移不会触发模型事件
        Role::flushCache();
    }

    protected function makeUser(string $role = 'user', array $attrs = []): User
    {
        return User::factory()->create(['role' => $role, ...$attrs]);
    }

    /** 清除守卫缓存（测试令牌注销等需要强制重新认证的场景） */
    protected function refreshAuth(): static
    {
        $this->app->make('auth')->forgetGuards();

        return $this;
    }

    /** 以真实 Sanctum token 调用 API（覆盖完整认证链路） */
    protected function actingAsApi(User $user): static
    {
        // RequestGuard 会缓存已解析用户，同一测试进程内换 token 也不会重新解析；
        // 丢弃缓存的守卫实例，保证每次请求都按 Authorization 头重新认证
        $this->app->make('auth')->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;
        $this->flushHeaders();
        $this->withHeader('Authorization', "Bearer {$token}");
        $this->withHeader('Accept', 'application/json');

        return $this;
    }
}
