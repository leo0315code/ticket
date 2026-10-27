<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SearchTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_ticket_keyword_matches_content_and_reply_body(): void
    {
        $owner = $this->makeUser();
        $agent = $this->makeUser('agent');

        $contentHit = Ticket::factory()->create([
            'user_id' => $owner->id,
            'title' => '普通标题',
            'content' => '导出报表时报错 500，堆栈里提到 memory_limit',
        ]);
        $replyHit = Ticket::factory()->create([
            'user_id' => $owner->id,
            'title' => '另一个工单',
            'content' => '无关描述',
        ]);
        $replyHit->replies()->create(['user_id' => $owner->id, 'content' => '换成 UTF-8 编码后就好了']);

        $res = $this->actingAsApi($agent)->getJson('/api/tickets?keyword='.urlencode('memory_limit'))
            ->assertOk();
        $this->assertSame([$contentHit->id], array_column($res->json('data'), 'id'));

        $res = $this->actingAsApi($agent)->getJson('/api/tickets?keyword='.urlencode('UTF-8'))
            ->assertOk();
        $this->assertSame([$replyHit->id], array_column($res->json('data'), 'id'));
    }

    public function test_keyword_still_matches_ticket_no_and_title(): void
    {
        $agent = $this->makeUser('agent');
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'title' => '登录异常']);

        $this->actingAsApi($agent)->getJson('/api/tickets?keyword='.urlencode($ticket->no))
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAsApi($agent)->getJson('/api/tickets?keyword='.urlencode('登录'))
            ->assertOk()->assertJsonPath('meta.total', 1);
    }
}
