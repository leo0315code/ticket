<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use App\Notifications\TicketEventNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

class WeChatNotifyTest extends ApiTestCase
{
    private function enableWeChat(): void
    {
        \App\Support\Settings::put([
            'wechat_notify_enabled' => true,
            'wechat_app_id' => 'wx_test',
            'wechat_app_secret' => 'secret',
            'wechat_reply_template_id' => 'TPL_REPLY',
            'wechat_status_template_id' => 'TPL_STATUS',
        ]);
    }

    private function fakeWeChat(): void
    {
        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'TOKEN', 'expires_in' => 7200]),
            'https://api.weixin.qq.com/cgi-bin/message/template/send*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);
    }

    public function test_reply_event_sends_template_to_bound_customer(): void
    {
        $this->enableWeChat();
        $this->fakeWeChat();
        config(['queue.default' => 'sync']);

        $customer = $this->makeUser('user', ['wechat_openid' => 'openid_123']);
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);

        $customer->notify(new TicketEventNotification($ticket, '客服回复了您的工单', 'replied'));

        Http::assertSent(fn ($req) => str_contains($req->url(), 'message/template/send')
            && $req['touser'] === 'openid_123'
            && $req['template_id'] === 'TPL_REPLY'
            && $req['data']['keyword1']['value'] === $ticket->no);
    }

    public function test_status_event_uses_status_template(): void
    {
        $this->enableWeChat();
        $this->fakeWeChat();
        config(['queue.default' => 'sync']);

        $customer = $this->makeUser('user', ['wechat_openid' => 'openid_456']);
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);

        $customer->notify(new TicketEventNotification($ticket, '工单状态已更新', 'updated'));

        Http::assertSent(fn ($req) => str_contains($req->url(), 'template/send') && $req['template_id'] === 'TPL_STATUS');
    }

    public function test_no_send_when_disabled_or_unbound(): void
    {
        $this->fakeWeChat();
        config(['queue.default' => 'sync']);

        // 未开启
        $customer = $this->makeUser('user', ['wechat_openid' => 'openid_789']);
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);
        $customer->notify(new TicketEventNotification($ticket, 'x', 'replied'));

        // 开启但未绑定 openid
        $this->enableWeChat();
        $other = $this->makeUser();
        $other->notify(new TicketEventNotification($ticket, 'x', 'replied'));

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'template/send'));
    }
}
