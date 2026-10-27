<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Ticket;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SettingsTest extends ApiTestCase
{
    public function test_admin_can_read_and_update_settings(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAsApi($this->makeUser('agent'))
            ->getJson('/api/settings')
            ->assertForbidden();

        $this->actingAsApi($admin)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('auto_close_days', 7)
            ->assertJsonPath('sla_hours.urgent', 4);

        $this->actingAsApi($admin)->putJson('/api/settings', [
            'site_name' => '售后支持',
            'auto_close_days' => 3,
            'notify_mail' => false,
            'sla_hours' => ['urgent' => 2, 'bogus' => 99],
        ])->assertOk()
            ->assertJsonPath('site_name', '售后支持')
            ->assertJsonPath('auto_close_days', 3)
            ->assertJsonPath('notify_mail', false)
            ->assertJsonPath('sla_hours.urgent', 2)
            ->assertJsonPath('sla_hours.high', 8)
            ->assertJsonMissingPath('sla_hours.bogus');
    }

    public function test_settings_validation_rejects_out_of_range(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAsApi($admin)->putJson('/api/settings', [
            'auto_close_days' => 0,
            'sla_hours' => ['urgent' => 500],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['auto_close_days', 'sla_hours.urgent']);
    }

    public function test_sla_hours_setting_applies_to_new_tickets(): void
    {
        Settings::put(['sla_hours' => ['urgent' => 2, 'high' => 8, 'medium' => 24, 'low' => 72]]);

        $category = Category::create(['name' => '设备报修']);
        $res = $this->actingAsApi($this->makeUser())->postJson('/api/tickets', [
            'title' => '打印机坏了',
            'content' => '一直卡纸',
            'priority' => 'urgent',
            'category_id' => $category->id,
        ])->assertCreated();

        $ticket = Ticket::findOrFail($res->json('id'));
        $this->assertSame(120, (int) $ticket->created_at->diffInMinutes($ticket->sla_due_at));
    }

    public function test_registration_switch_is_exposed_in_meta(): void
    {
        $this->getJson('/api/meta')
            ->assertOk()
            ->assertJsonPath('allow_register', true)
            ->assertJsonPath('site_name', '工单支持中心');

        Settings::put(['allow_register' => false, 'site_name' => '自建售后']);

        $this->getJson('/api/meta')
            ->assertJsonPath('allow_register', false)
            ->assertJsonPath('site_name', '自建售后');

    }

    public function test_reopen_limit_setting_blocks_reopen(): void
    {
        Settings::put(['reopen_limit' => 0]);

        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create([
            'user_id' => $owner->id,
            'status' => 'closed',
            'closed_at' => now(),
            'closed_reason' => 'manual',
        ]);

        $this->actingAsApi($owner)->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('can_reopen', false);

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/reopen")
            ->assertStatus(422);
    }

    public function test_auto_close_uses_configured_days(): void
    {
        Settings::put(['auto_close_days' => 2]);

        $stale = Ticket::factory()->create([
            'status' => 'pending_resolved',
            'last_activity_at' => now()->subDays(3),
        ]);
        $fresh = Ticket::factory()->create([
            'status' => 'pending_resolved',
            'last_activity_at' => now()->subDay(),
        ]);

        $this->artisan('tickets:auto-close')->assertSuccessful();

        $this->assertSame('closed', $stale->fresh()->status);
        $this->assertSame('auto', $stale->fresh()->closed_reason);
        $this->assertSame('pending_resolved', $fresh->fresh()->status);
    }

    public function test_attachment_limit_uses_setting(): void
    {
        Storage::fake('local');
        Settings::put(['attach_max_mb' => 1]);

        $user = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $user->id]);

        $this->actingAsApi($user)->postJson("/api/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->actingAsApi($user)->postJson("/api/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->create('small.pdf', 512, 'application/pdf'),
        ])->assertCreated();
    }

    public function test_sms_provider_is_configurable_and_secrets_are_masked(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAsApi($admin)->putJson('/api/settings', [
            'sms_provider' => 'aliyun',
            'sms_aliyun_key' => 'LTAI-test',
            'sms_aliyun_secret' => 'super-secret',
            'sms_aliyun_sign' => '工单支持中心',
            'sms_aliyun_template' => 'SMS_123456789',
        ])->assertOk();

        $this->assertSame('aliyun', Settings::get('sms_provider'));
        $this->assertSame('super-secret', Settings::get('sms_aliyun_secret'));

        $res = $this->actingAsApi($admin)->getJson('/api/settings')->assertOk();
        $res->assertJsonPath('sms_aliyun_secret', '')
            ->assertJsonPath('sms_aliyun_secret_set', true)
            ->assertJsonPath('sms_aliyun_template', 'SMS_123456789');

        // 留空保存不会把密钥清掉
        $this->actingAsApi($admin)->putJson('/api/settings', ['sms_aliyun_secret' => ''])->assertOk();
        $this->assertSame('super-secret', Settings::get('sms_aliyun_secret'));

        $this->actingAsApi($admin)->putJson('/api/settings', ['sms_provider' => 'unknown'])
            ->assertStatus(422)->assertJsonValidationErrors('sms_provider');
    }

    public function test_sms_test_send_uses_aliyun_tencent_and_yunpian_apis(): void
    {
        $admin = $this->makeUser('admin');

        // 阿里云
        Settings::put([
            'sms_provider' => 'aliyun',
            'sms_aliyun_key' => 'k', 'sms_aliyun_secret' => 's',
            'sms_aliyun_sign' => '签名', 'sms_aliyun_template' => 'SMS_1',
        ]);
        Http::fake(['dysmsapi.aliyuncs.com/*' => Http::response(['Code' => 'OK', 'Message' => 'OK'])]);
        $this->actingAsApi($admin)->postJson('/api/settings/sms-test', ['phone' => '13800138000'])
            ->assertOk()->assertJsonPath('message', '测试短信已发送，请查收');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'dysmsapi.aliyuncs.com')
            && str_contains($request->url(), 'TemplateCode=SMS_1')
            && str_contains($request->url(), 'Signature='));

        // 腾讯云
        Settings::put([
            'sms_provider' => 'tencent',
            'sms_tencent_secret_id' => 'id', 'sms_tencent_secret_key' => 'key',
            'sms_tencent_app_id' => '1400000000', 'sms_tencent_sign' => '签名',
            'sms_tencent_template' => '1234567',
        ]);
        Http::fake(['sms.tencentcloudapi.com*' => Http::response([
            'Response' => ['SendStatusSet' => [['Code' => 'Ok', 'Message' => 'send success']]],
        ])]);
        $this->actingAsApi($admin)->postJson('/api/settings/sms-test', ['phone' => '13800138000'])->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->header('Authorization')[0] ?? '', 'TC3-HMAC-SHA256 Credential=id/'));

        // 云片
        Settings::put(['sms_provider' => 'yunpian', 'sms_yunpian_key' => 'yp']);
        Http::fake(['sms.yunpian.com/*' => Http::response(['code' => 0, 'msg' => 'OK'])]);
        $this->actingAsApi($admin)->postJson('/api/settings/sms-test', ['phone' => '13800138000'])->assertOk();

        $this->actingAsApi($admin)->postJson('/api/settings/sms-test', ['phone' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_sms_test_send_surfaces_provider_error(): void
    {
        $admin = $this->makeUser('admin');
        Settings::put(['sms_provider' => 'yunpian', 'sms_yunpian_key' => 'yp']);

        Http::fake(['sms.yunpian.com/*' => Http::response(['code' => 22, 'msg' => '余额不足'])]);

        $this->actingAsApi($admin)->postJson('/api/settings/sms-test', ['phone' => '13800138000'])
            ->assertStatus(422)->assertJsonPath('message', '短信发送失败：余额不足');
    }
}
