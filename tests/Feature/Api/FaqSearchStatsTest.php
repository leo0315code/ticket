<?php

namespace Tests\Feature\Api;

use App\Models\Faq;
use App\Models\FaqSearchLog;

class FaqSearchStatsTest extends ApiTestCase
{
    public function test_search_is_logged_with_results_count(): void
    {
        Faq::create(['question' => '如何重置密码', 'answer' => '登录页点忘记密码', 'is_public' => true]);

        $this->getJson('/api/faqs?'.http_build_query(['q' => '重置']))->assertOk();
        $this->getJson('/api/faqs?'.http_build_query(['q' => '不存在的词xyz']))->assertOk();

        $this->assertDatabaseHas('faq_search_logs', ['keyword' => '重置', 'results_count' => 1]);
        $this->assertDatabaseHas('faq_search_logs', ['keyword' => '不存在的词xyz', 'results_count' => 0]);
    }

    public function test_search_stats_requires_permission_and_aggregates(): void
    {
        $this->getJson('/api/faqs/search-stats')->assertUnauthorized();
        $this->actingAsApi($this->makeUser())->getJson('/api/faqs/search-stats')->assertForbidden();

        FaqSearchLog::create(['keyword' => '退款', 'results_count' => 0]);
        FaqSearchLog::create(['keyword' => '退款', 'results_count' => 0]);
        FaqSearchLog::create(['keyword' => '发票', 'results_count' => 0]);
        FaqSearchLog::create(['keyword' => '登录', 'results_count' => 3]);
        Faq::create(['question' => '差的内容', 'answer' => 'x', 'helpful_yes' => 1, 'helpful_no' => 9]);

        $data = $this->actingAsApi($this->makeUser('admin'))->getJson('/api/faqs/search-stats')->assertOk()->json();

        $this->assertSame('退款', $data['missed'][0]['keyword']);
        $this->assertSame(2, $data['missed'][0]['times']);
        $this->assertSame('差的内容', $data['unhelpful'][0]['question']);
        $this->assertSame(4, $data['total_searches']);
    }
}
