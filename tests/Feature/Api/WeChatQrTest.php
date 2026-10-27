<?php

namespace Tests\Feature\Api;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;

class WeChatQrTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::put([
            'wechat_login_enabled' => true,
            'wechat_mode' => 'mp',
            'wechat_app_id' => 'wx_test',
            'wechat_app_secret' => 'secret',
        ]);
    }

    private function fakeWechat(string $openid = 'openid-1', ?string $unionid = null): void
    {
        Http::fake([
            'api.weixin.qq.com/sns/oauth2/access_token*' => Http::response([
                'access_token' => 'at', 'openid' => $openid, 'unionid' => $unionid,
            ]),
            'api.weixin.qq.com/sns/userinfo*' => Http::response([
                'nickname' => '小明', 'headimgurl' => 'https://img/x.png',
            ]),
        ]);
    }

    public function test_start_returns_scene_and_authorize_url(): void
    {
        $res = $this->postJson('/api/auth/wechat/qr')->assertOk();

        $scene = $res->json('scene');
        $this->assertSame(32, strlen($scene));
        $url = $res->json('url');
        $this->assertStringContainsString('open.weixin.qq.com/connect/oauth2/authorize', $url);
        $this->assertStringContainsString('state=qr_'.$scene, $url);
        $this->assertStringContainsString('scope=snsapi_userinfo', $url);
    }

    public function test_start_requires_mp_mode_and_config(): void
    {
        Settings::put(['wechat_mode' => 'open']);
        $this->postJson('/api/auth/wechat/qr')->assertStatus(422);

        Settings::put(['wechat_mode' => 'mp', 'wechat_app_secret' => '']);
        $this->postJson('/api/auth/wechat/qr')->assertStatus(422);
    }

    public function test_full_scan_flow_issues_token_once(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['wechat_openid' => 'openid-1'])->save();
        $this->fakeWechat('openid-1');

        $scene = $this->postJson('/api/auth/wechat/qr')->json('scene');

        // 未扫码前是 pending
        $this->getJson("/api/auth/wechat/qr/{$scene}")->assertOk()->assertJsonPath('status', 'pending');

        // 手机端确认
        $this->postJson('/api/auth/wechat/qr/confirm', ['scene' => $scene, 'code' => 'code-1'])
            ->assertOk()
            ->assertJsonPath('message', '登录成功，请回到电脑端继续');

        // 电脑端取到登录态
        $res = $this->getJson("/api/auth/wechat/qr/{$scene}")->assertOk();
        $res->assertJsonPath('status', 'ok')->assertJsonPath('user.id', $user->id);
        $this->assertNotEmpty($res->json('token'));
        $this->assertNotNull($user->fresh()->last_login_at);

        // 只能取一次
        $this->getJson("/api/auth/wechat/qr/{$scene}")->assertJsonPath('status', 'expired');
    }

    public function test_unionid_is_matched_when_openid_differs(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['wechat_openid' => 'old-openid', 'wechat_unionid' => 'union-1'])->save();
        $this->fakeWechat('new-openid', 'union-1');

        $scene = $this->postJson('/api/auth/wechat/qr')->json('scene');
        $this->postJson('/api/auth/wechat/qr/confirm', ['scene' => $scene, 'code' => 'c'])->assertOk();

        $this->assertSame($user->id, $this->getJson("/api/auth/wechat/qr/{$scene}")->json('user.id'));
    }

    public function test_unbound_wechat_and_bad_scene_are_rejected(): void
    {
        $this->fakeWechat('stranger');

        $scene = $this->postJson('/api/auth/wechat/qr')->json('scene');
        $this->postJson('/api/auth/wechat/qr/confirm', ['scene' => $scene, 'code' => 'c'])
            ->assertStatus(422)
            ->assertJsonPath('message', '该微信还没有绑定账号，请先在手机或电脑上用手机号/账号登录，并在「账号设置」里绑定微信');

        $this->postJson('/api/auth/wechat/qr/confirm', ['scene' => str_repeat('a', 32), 'code' => 'c'])
            ->assertStatus(422);

        $this->getJson('/api/auth/wechat/qr/'.str_repeat('b', 32))->assertJsonPath('status', 'expired');
    }
}
