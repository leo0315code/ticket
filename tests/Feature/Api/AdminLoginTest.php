<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class AdminLoginTest extends ApiTestCase
{
    use RefreshDatabase;

    private function issueKey(): string
    {
        return $this->getJson('/api/captcha/issue')->json('key');
    }

    private function ticket(string $key): string
    {
        return $this->postJson('/api/captcha/verify', ['key' => $key])->json('ticket');
    }

    public function test_admin_login_requires_captcha_ticket(): void
    {
        $agent = $this->makeUser('agent', ['username' => 'agent_x']);

        // 无 ticket
        $this->postJson('/api/admin/login', [
            'account' => 'agent_x', 'password' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('captcha_ticket');

        // 伪造 ticket
        $this->postJson('/api/admin/login', [
            'account' => $agent->username, 'password' => 'password', 'captcha_ticket' => 'fake',
        ])->assertStatus(422)->assertJsonPath('message', '请先完成滑块验证');
    }

    public function test_admin_login_full_flow(): void
    {
        $agent = $this->makeUser('agent', ['username' => 'agent_a', 'password' => bcrypt('secret123')]);

        $key = $this->issueKey();
        $ticket = $this->ticket($key);

        $res = $this->postJson('/api/admin/login', [
            'account' => 'agent_a',
            'password' => 'secret123',
            'captcha_ticket' => $ticket,
        ])->assertOk()->assertJsonStructure(['token', 'user']);

        $this->assertSame($agent->id, $res->json('user.id'));

        // 票据一次性：再用同一个 ticket 应失败
        $this->postJson('/api/admin/login', [
            'account' => 'agent_a', 'password' => 'secret123', 'captcha_ticket' => $ticket,
        ])->assertStatus(422);
    }

    public function test_normal_user_rejected_on_admin_login(): void
    {
        $user = $this->makeUser('user', ['username' => 'u1', 'password' => bcrypt('secret123')]);

        $ticket = $this->ticket($this->issueKey());
        $this->postJson('/api/admin/login', [
            'account' => 'u1', 'password' => 'secret123', 'captcha_ticket' => $ticket,
        ])->assertStatus(403);
    }

    public function test_login_rate_limited_after_5_attempts(): void
    {
        $agent = $this->makeUser('agent', ['username' => 'rl_agent']);

        // 10 次错误密码（默认阈值 login_rate_limit=10）
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'account' => 'rl_agent', 'password' => "wrong-$i",
            ])->assertStatus(401);
        }

        // 第 11 次：429（即使密码正确）
        $this->postJson('/api/login', [
            'account' => 'rl_agent', 'password' => 'password',
        ])->assertStatus(429);
    }

    public function test_captcha_key_single_use(): void
    {
        $key = $this->issueKey();
        $this->postJson('/api/captcha/verify', ['key' => $key])->assertOk();
        // 复用 key 换票失败
        $this->postJson('/api/captcha/verify', ['key' => $key])->assertStatus(422);
    }
}
