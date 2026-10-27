<?php

namespace Tests\Feature\Api;

use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FaqTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_customer_only_sees_public_entries(): void
    {
        Faq::create(['question' => '公开问题', 'answer' => '公开答案', 'is_public' => true]);
        Faq::create(['question' => '内部问题', 'answer' => '内部答案', 'is_public' => false]);

        $this->actingAsApi($this->makeUser())->getJson('/api/faqs')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', '公开问题');

        $this->actingAsApi($this->makeUser('agent'))->getJson('/api/faqs')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_search_matches_question_and_answer(): void
    {
        Faq::create(['question' => '如何修改密码', 'answer' => '在个人中心修改', 'is_public' => true]);
        Faq::create(['question' => '发票怎么开', 'answer' => '联系客服开具', 'is_public' => true]);

        $url = fn (string $q) => '/api/faqs?'.http_build_query(['q' => $q]);

        $this->actingAsApi($this->makeUser())->getJson($url('密码'))
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAsApi($this->makeUser())->getJson($url('客服'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', '发票怎么开');
    }

    public function test_view_and_feedback_counters(): void
    {
        $faq = Faq::create(['question' => 'Q', 'answer' => 'A', 'is_public' => true]);
        $user = $this->makeUser();

        $this->actingAsApi($user)->postJson("/api/faqs/{$faq->id}/view")->assertOk()->assertJsonPath('views', 1);
        $this->actingAsApi($user)->postJson("/api/faqs/{$faq->id}/feedback", ['helpful' => true])
            ->assertOk()->assertJsonPath('helpful_yes', 1);
        $this->actingAsApi($user)->postJson("/api/faqs/{$faq->id}/feedback", ['helpful' => false])
            ->assertOk()->assertJsonPath('helpful_no', 1);
    }

    public function test_admin_can_manage_help_center_entries(): void
    {
        $admin = $this->makeUser('admin');

        $id = $this->actingAsApi($admin)->postJson('/api/faqs', [
            'question' => '新问题',
            'answer' => '新答案',
            'category' => '账号',
            'is_public' => false,
            'sort' => 2,
        ])->assertCreated()->assertJsonPath('category', '账号')->json('id');

        $this->actingAsApi($admin)->putJson("/api/faqs/{$id}", [
            'question' => '改后的问题',
            'answer' => '改后的答案',
            'category' => '账号',
            'is_public' => true,
        ])->assertOk()->assertJsonPath('question', '改后的问题');

        $this->actingAsApi($admin)->deleteJson("/api/faqs/{$id}")->assertOk();
        $this->assertDatabaseMissing('faqs', ['id' => $id]);
    }
}
