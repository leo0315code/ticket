<?php

namespace Tests\Feature\Api;

use App\Models\Faq;

class PublicHelpTest extends ApiTestCase
{
    public function test_guest_can_browse_only_public_faqs(): void
    {
        Faq::create(['question' => '怎么退款', 'answer' => '在订单页申请', 'is_public' => true, 'category' => '支付']);
        Faq::create(['question' => '内部排查手册', 'answer' => '仅客服可见', 'is_public' => false]);

        $res = $this->getJson('/api/faqs')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('怎么退款', $res->json('data.0.question'));
        $this->assertSame('支付', $res->json('data.0.category'));
    }

    public function test_guest_can_search_and_filter_category(): void
    {
        Faq::create(['question' => '发票怎么开', 'answer' => '联系客服', 'is_public' => true, 'category' => '支付']);
        Faq::create(['question' => '如何登录', 'answer' => '账号密码', 'is_public' => true, 'category' => '账号']);

        $res = $this->getJson('/api/faqs?'.http_build_query(['q' => '发票']))->assertOk();
        $this->assertCount(1, $res->json('data'));

        $byCategory = $this->getJson('/api/faqs?'.http_build_query(['q' => '账号']))->assertOk();
        $this->assertSame('如何登录', $byCategory->json('data.0.question'));
    }

    public function test_guest_can_view_and_vote_faq(): void
    {
        $faq = Faq::create(['question' => 'Q', 'answer' => 'A', 'is_public' => true]);

        $this->postJson("/api/faqs/{$faq->id}/view")->assertOk();
        $this->postJson("/api/faqs/{$faq->id}/feedback", ['helpful' => true])->assertOk();
        $this->postJson("/api/faqs/{$faq->id}/feedback", ['helpful' => false])->assertOk();

        $faq->refresh();
        $this->assertSame(1, $faq->views);
        $this->assertSame(1, $faq->helpful_yes);
        $this->assertSame(1, $faq->helpful_no);
    }

    public function test_guest_cannot_manage_faqs(): void
    {
        $this->postJson('/api/faqs', ['question' => 'x', 'answer' => 'y'])->assertUnauthorized();
    }
}
