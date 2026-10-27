<?php

namespace Tests\Feature\Api;

class AuthTest extends ApiTestCase
{
    public function test_register_creates_user_with_default_role_and_returns_token(): void
    {
        // 不填邮箱，仅用用户名注册
        $res = $this->postJson('/api/register', [
            'name' => '新用户',
            'username' => 'newuser',
            'password' => 'password123',
        ]);

        $res->assertCreated()->assertJsonPath('user.role', 'user');
        $this->assertDatabaseHas('users', ['username' => 'newuser', 'email' => null, 'role' => 'user']);
    }

    public function test_register_rejects_duplicate_email_and_short_password(): void
    {
        \App\Models\User::factory()->create(['username' => 'taken']);

        $this->postJson('/api/register', [
            'name' => 'a', 'username' => 'taken', 'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('username');

        $this->postJson('/api/register', [
            'name' => 'a', 'username' => 'ok_name', 'password' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_login_by_username_or_email_and_wrong_password_is_401(): void
    {
        $user = \App\Models\User::factory()->create([
            'username' => 'tester', 'password' => bcrypt('secret123'),
        ]);

        // 用户名登录
        $this->postJson('/api/login', ['account' => 'tester', 'password' => 'secret123'])
            ->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'username', 'role']]);

        // 邮箱登录（兼容老用户）
        if ($user->email) {
            $this->postJson('/api/login', ['account' => $user->email, 'password' => 'secret123'])->assertOk();
        }

        $this->postJson('/api/login', ['account' => 'tester', 'password' => 'wrong-pass'])
            ->assertStatus(401);
    }

    public function test_protected_routes_require_auth(): void
    {
        $this->getJson('/api/tickets')->assertStatus(401);
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_me_and_logout_with_valid_token(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAsApi($user)
            ->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $user->id);

        $this->actingAsApi($user)
            ->postJson('/api/logout')->assertOk();

        // 注销后旧 token 失效
        $this->refreshAuth()->getJson('/api/tickets')->assertStatus(401);
    }

    public function test_profile_allows_binding_and_clearing_optional_email(): void
    {
        \App\Models\User::factory()->create(['username' => 'taken_mail', 'email' => 'me@example.com']);
        $user = \App\Models\User::factory()->create(['username' => 'profile_u', 'email' => null]);

        // 未填邮箱也能注册/使用，之后可随时补绑
        $this->actingAsApi($user)
            ->putJson('/api/profile', ['name' => '新昵称', 'email' => 'profile_u@example.com'])
            ->assertOk()
            ->assertJsonPath('user.name', '新昵称')
            ->assertJsonPath('user.email', 'profile_u@example.com');

        // 邮箱重复
        $this->actingAsApi($user)
            ->putJson('/api/profile', ['name' => '新昵称', 'email' => 'me@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        // 也可以解绑（留空）
        $this->actingAsApi($user)
            ->putJson('/api/profile', ['name' => '新昵称', 'email' => null])
            ->assertOk()->assertJsonPath('user.email', null);
    }

    public function test_mail_channel_is_skipped_when_email_missing(): void
    {
        $ticket = \App\Models\Ticket::factory()->create();
        $notification = new \App\Notifications\TicketEventNotification($ticket, '有新回复', 'replied');

        $noEmail = \App\Models\User::factory()->create(['email' => null]);
        $withEmail = \App\Models\User::factory()->create(['email' => 'staff@example.com']);

        $channels = $notification->via($noEmail);
        $this->assertContains('database', $channels);
        $this->assertNotContains('mail', $channels, '没绑邮箱不应尝试发邮件');

        $this->assertContains('mail', $notification->via($withEmail));
    }
}
