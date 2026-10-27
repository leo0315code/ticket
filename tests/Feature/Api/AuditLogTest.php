<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuditLogTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_role_changes_are_audited(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAsApi($admin)->postJson('/api/roles', [
            'name' => 'support_lead',
            'label' => '客服主管',
            'permissions' => ['panel.access', 'ticket.view_all'],
        ])->assertCreated();

        $role = Role::where('name', 'support_lead')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role_created',
            'target_type' => 'Role',
            'target_id' => $role->id,
            'actor_id' => $admin->id,
        ]);

        $this->actingAsApi($admin)->putJson("/api/roles/{$role->id}", [
            'label' => '客服组长',
            'permissions' => ['panel.access'],
        ])->assertOk();

        $log = AuditLog::where('action', 'role_updated')->latest('id')->firstOrFail();
        $this->assertSame('客服主管', $log->meta['before']['label']);
        $this->assertSame('客服组长', $log->meta['after']['label']);
    }

    public function test_user_role_change_and_settings_change_are_audited_without_secrets(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser('user');
        $agent = $this->makeUser('agent');
        $agent->forceFill(['role' => 'agent'])->save();

        $this->actingAsApi($admin)->putJson("/api/users/{$agent->id}", ['role' => 'user'])->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_role_changed', 'target_id' => $agent->id, 'actor_id' => $admin->id,
        ]);

        $this->actingAsApi($admin)->putJson('/api/settings', [
            'site_name' => '有赞工单',
            'wechat_app_secret' => 'super-secret-value',
        ])->assertOk();

        $log = AuditLog::where('action', 'settings_updated')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('super-secret-value', json_encode($log->meta));
        $this->assertContains('wechat_app_secret', $log->meta['secrets_changed']);
        $this->assertContains('site_name', $log->meta['keys']);
    }

    public function test_audit_log_listing_requires_permission(): void
    {
        $this->actingAsApi($this->makeUser('agent'))->getJson('/api/audit-logs')->assertOk();
        $this->actingAsApi($this->makeUser())->getJson('/api/audit-logs')->assertStatus(403);
    }
}
