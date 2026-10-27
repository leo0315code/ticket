<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Sms\SmsManager;
use App\Support\Sms\SmsSender;
use App\Support\Settings;
use App\Support\WeChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FakeSmsSender implements SmsSender
{
    /** @var list<array{phone:string,code:string,purpose:string}> */
    public array $sent = [];

    public function send(string $phone, string $code, string $purpose): void
    {
        $this->sent[] = compact('phone', 'code', 'purpose');
    }

    public function lastCode(): string
    {
        return end($this->sent)['code'];
    }
}

class LoginMethodTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();
        $this->sms = new FakeSmsSender();
        $this->app->instance(SmsSender::class, $this->sms);
    }

    private function enablePhone(): void
    {
        Settings::put(['phone_login_enabled' => true]);
    }

    private function enableWechat(): void
    {
        Settings::put([
            'wechat_login_enabled' => true,
            'wechat_app_id' => 'wx-test-app',
            'wechat_app_secret' => 'wx-test-secret',
            'wechat_mode' => 'open',
        ]);
    }

    private function userWithPhone(string $phone = '13800138000'): User
    {
        return User::create([
            'name' => '手机用户',
            'username' => 'phoneuser',
            'phone' => $phone,
            'password' => 'password123',
            'role' => 'user',
        ]);
    }

    public function test_phone_login_disabled_by_default(): void
    {
        $this->postJson('/api/auth/phone/code', ['phone' => '13800138000'])->assertStatus(403);
    }

    public function test_phone_code_login_success(): void
    {
        $this->enablePhone();
        $user = $this->userWithPhone();

        $this->postJson('/api/auth/phone/code', ['phone' => '13800138000'])
            ->assertOk()
            ->assertJsonPath('message', '验证码已发送');

        $code = $this->sms->lastCode();

        $this->postJson('/api/auth/phone/login', ['phone' => '13800138000', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token']);
    }

    public function test_wrong_code_is_rejected_and_limited(): void
    {
        $this->enablePhone();
        $this->userWithPhone();
        $this->postJson('/api/auth/phone/code', ['phone' => '13800138000']);

        for ($i = 0; $i < SmsManager::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/auth/phone/login', ['phone' => '13800138000', 'code' => '000000'])
                ->assertStatus(422);
        }

        // 尝试次数用尽后，即使验证码正确也已作废
        $this->postJson('/api/auth/phone/login', ['phone' => '13800138000', 'code' => $this->sms->lastCode()])
            ->assertStatus(422);
    }

    public function test_unregistered_phone_auto_registers_and_logs_in(): void
    {
        $this->enablePhone();
        $this->postJson('/api/auth/phone/code', ['phone' => '13900139000'])->assertOk();

        $response = $this->postJson('/api/auth/phone/login', ['phone' => '13900139000', 'code' => $this->sms->lastCode()])
            ->assertOk()
            ->assertJsonPath('user.role', 'user')
            ->assertJsonPath('user.has_phone', true);

        $user = User::where('phone', '13900139000')->firstOrFail();
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertSame('用户9000', $user->name);
        $this->assertSame('p13900139000', $user->username);

        // 同一个手机号再次登录走同一个账号，不会重复建号
        $this->assertSame(1, User::where('phone', '13900139000')->count());
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_register_closed_blocks_phone_auto_register(): void
    {
        $this->enablePhone();
        Settings::put(['allow_register' => false]);
        $this->postJson('/api/auth/phone/code', ['phone' => '13900139001'])->assertOk();

        $this->postJson('/api/auth/phone/login', ['phone' => '13900139001', 'code' => $this->sms->lastCode()])
            ->assertStatus(403)
            ->assertJsonPath('message', '注册已关闭，请联系管理员开通账号');

        $this->assertDatabaseMissing('users', ['phone' => '13900139001']);
    }

    public function test_inactive_user_cannot_login_by_phone(): void
    {
        $this->enablePhone();
        $user = $this->userWithPhone();
        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/auth/phone/code', ['phone' => '13800138000']);
        $this->postJson('/api/auth/phone/login', ['phone' => '13800138000', 'code' => $this->sms->lastCode()])
            ->assertStatus(403);
    }

    public function test_bind_phone_then_login_with_it(): void
    {
        $this->enablePhone();
        $user = User::create([
            'name' => '待绑定',
            'username' => 'bindme',
            'password' => 'password123',
            'role' => 'user',
        ]);

        $this->actingAs($user)->postJson('/api/profile/phone/code', ['phone' => '13700137000'])->assertOk();
        $code = $this->sms->lastCode();

        $this->actingAs($user)->putJson('/api/profile/phone', ['phone' => '13700137000', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.has_phone', true);

        $this->postJson('/api/auth/phone/code', ['phone' => '13700137000'])->assertOk();
        $this->postJson('/api/auth/phone/login', ['phone' => '13700137000', 'code' => $this->sms->lastCode()])
            ->assertOk();
    }

    public function test_bind_phone_rejects_duplicate(): void
    {
        $this->enablePhone();
        $this->userWithPhone('13800138000');
        $other = User::create(['name' => 'B', 'username' => 'buser', 'password' => 'password123', 'role' => 'user']);

        $this->actingAs($other)->putJson('/api/profile/phone', ['phone' => '13800138000', 'code' => '123456'])
            ->assertStatus(422);
    }

    public function test_wechat_disabled_returns_403(): void
    {
        $this->getJson('/api/auth/wechat/url')->assertStatus(403);
    }

    public function test_wechat_url_uses_qrconnect_in_open_mode(): void
    {
        $this->enableWechat();

        $response = $this->getJson('/api/auth/wechat/url')->assertOk();
        $url = $response->json('url');

        $this->assertStringContainsString('open.weixin.qq.com/connect/qrconnect', $url);
        $this->assertStringContainsString('scope=snsapi_login', $url);
        $this->assertStringContainsString('appid=wx-test-app', $url);
        $this->assertNotEmpty($response->json('state'));
    }

    public function test_wechat_login_success(): void
    {
        $this->enableWechat();
        Http::fake([
            'api.weixin.qq.com/sns/oauth2/access_token*' => Http::response([
                'access_token' => 'at', 'openid' => 'openid-1', 'unionid' => 'union-1',
            ]),
            'api.weixin.qq.com/sns/userinfo*' => Http::response(['nickname' => '微信用户', 'headimgurl' => 'https://x/a.png']),
        ]);

        $user = User::create([
            'name' => '微信用户', 'username' => 'wxuser', 'password' => 'password123', 'role' => 'user',
            'wechat_openid' => 'openid-1',
        ]);

        $state = $this->getJson('/api/auth/wechat/url')->json('state');
        $this->postJson('/api/auth/wechat/login', ['code' => 'wx-code', 'state' => $state])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_wechat_unbound_openid_rejected(): void
    {
        $this->enableWechat();
        Http::fake([
            'api.weixin.qq.com/sns/oauth2/access_token*' => Http::response(['access_token' => 'at', 'openid' => 'openid-2']),
            'api.weixin.qq.com/sns/userinfo*' => Http::response([]),
        ]);

        $state = $this->getJson('/api/auth/wechat/url')->json('state');
        $this->postJson('/api/auth/wechat/login', ['code' => 'wx-code', 'state' => $state])
            ->assertStatus(422);
    }

    public function test_wechat_bind_and_unbind(): void
    {
        $this->enableWechat();
        Http::fake([
            'api.weixin.qq.com/sns/oauth2/access_token*' => Http::response(['access_token' => 'at', 'openid' => 'openid-3']),
            'api.weixin.qq.com/sns/userinfo*' => Http::response(['nickname' => '绑定者']),
        ]);

        $other = User::create(['name' => 'C', 'username' => 'cuser', 'password' => 'password123', 'role' => 'user']);
        $state = $this->getJson('/api/auth/wechat/url')->json('state');

        $this->actingAs($other)->postJson('/api/profile/wechat/bind', ['code' => 'wx-code', 'state' => $state])
            ->assertOk()
            ->assertJsonPath('user.has_wechat', true);

        $other->refresh();
        $this->assertSame('openid-3', $other->wechat_openid);

        $this->actingAs($other)->deleteJson('/api/profile/wechat')->assertOk();
        $other->refresh();
        $this->assertNull($other->wechat_openid);
    }

    public function test_wechat_rejects_invalid_state(): void
    {
        $this->enableWechat();
        Http::fake(['api.weixin.qq.com/*' => Http::response(['openid' => 'x'])]);

        $this->postJson('/api/auth/wechat/login', ['code' => 'wx-code', 'state' => 'forged'])
            ->assertStatus(422);

        $this->assertTrue(WeChat::stateValid(WeChat::state()));
    }

    public function test_settings_mask_wechat_secret(): void
    {
        $admin = User::create([
            'name' => '管理员', 'username' => 'admin1', 'password' => 'password123', 'role' => 'admin',
        ]);

        $this->actingAs($admin)->putJson('/api/settings', [
            'wechat_login_enabled' => true,
            'wechat_app_id' => 'wx-id',
            'wechat_app_secret' => 'super-secret',
        ])->assertOk();

        $this->actingAs($admin)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('wechat_app_id', 'wx-id')
            ->assertJsonPath('wechat_app_secret', '')
            ->assertJsonPath('wechat_app_secret_set', true);

        // 留空保存不会覆盖已设置的密钥
        $this->actingAs($admin)->putJson('/api/settings', ['wechat_app_secret' => ''])->assertOk();
        $this->assertSame('super-secret', Settings::get('wechat_app_secret'));
    }
}
