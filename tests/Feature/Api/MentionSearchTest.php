<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MentionSearchTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_search_matches_username_and_name(): void
    {
        $this->makeUser('agent', ['username' => 'wangwu', 'name' => '小王']);
        $this->makeUser('admin', ['username' => 'admin', 'name' => '管理员']);

        $res = $this->actingAsApi($this->makeUser())
            ->getJson('/api/mention-search?q=wang')
            ->assertOk();
        $this->assertSame('wangwu', $res->json('data.0.username'));

        $res2 = $this->actingAsApi($this->makeUser())
            ->getJson('/api/mention-search?q='.rawurlencode('小王'))
            ->assertOk();
        $this->assertSame('wangwu', $res2->json('data.0.username'));

        // 空查询返回空列表
        $this->actingAsApi($this->makeUser())
            ->getJson('/api/mention-search?q=')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/mention-search?q=a')->assertStatus(401);
    }
}
