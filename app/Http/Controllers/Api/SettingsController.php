<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Settings;
use App\Support\Sms\SmsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /** 密钥类设置：不回传给前端，只告诉是否已设置 */
    private const SECRETS = [
        'wechat_app_secret',
        'sms_aliyun_secret',
        'sms_tencent_secret_key',
        'sms_yunpian_key',
    ];

    /** 系统设置（admin） */
    public function index(): JsonResponse
    {
        $all = Settings::all();
        foreach (self::SECRETS as $key) {
            $all[$key] = '';
            $all[$key.'_set'] = filled(Settings::get($key));
        }

        return response()->json($all);
    }

    /** 保存设置：字段都是可选的，做局部更新 */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_name' => ['sometimes', 'string', 'max:50'],
            'allow_register' => ['sometimes', 'boolean'],
            'sla_hours' => ['sometimes', 'array'],
            'sla_hours.urgent' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'sla_hours.high' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'sla_hours.medium' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'sla_hours.low' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'resolution_hours' => ['sometimes', 'array'],
            'resolution_hours.urgent' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'resolution_hours.high' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'resolution_hours.medium' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'resolution_hours.low' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'business_hours_enabled' => ['sometimes', 'boolean'],
            'business_hours_start' => ['sometimes', 'date_format:H:i'],
            'business_hours_end' => ['sometimes', 'date_format:H:i', 'after:business_hours_start'],
            'business_hours_days' => ['sometimes', 'array', 'min:1'],
            'business_hours_days.*' => ['integer', 'between:1,7'],
            'business_timezone' => ['sometimes', 'timezone'],
            'auto_close_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'reopen_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'reopen_limit' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'attach_max_mb' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'attach_max_count' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'attach_quota_mb' => ['sometimes', 'integer', 'min:0', 'max:10240'],
            'notify_mail' => ['sometimes', 'boolean'],

            'phone_login_enabled' => ['sometimes', 'boolean'],
            'sms_provider' => ['sometimes', 'in:log,aliyun,tencent,yunpian,http'],
            'sms_driver' => ['sometimes', 'in:log,http'],
            'sms_aliyun_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sms_aliyun_secret' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sms_aliyun_sign' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sms_aliyun_template' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sms_tencent_secret_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sms_tencent_secret_key' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sms_tencent_app_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sms_tencent_sign' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sms_tencent_template' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sms_tencent_region' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sms_yunpian_key' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sms_http_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'sms_sign' => ['sometimes', 'string', 'max:30'],
            'sms_code_ttl' => ['sometimes', 'integer', 'min:60', 'max:1800'],
            'wechat_login_enabled' => ['sometimes', 'boolean'],
            'wechat_mode' => ['sometimes', 'in:open,mp'],
            'wechat_app_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'wechat_app_secret' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        // 留空表示“不修改”，避免前端回显时把密钥清掉
        foreach (self::SECRETS as $key) {
            if (array_key_exists($key, $data) && blank($data[$key])) {
                unset($data[$key]);
            }
        }

        if (isset($data['resolution_hours'])) {
            $data['resolution_hours'] = array_intersect_key(
                array_replace(Settings::get('resolution_hours'), $data['resolution_hours']),
                ['urgent' => 1, 'high' => 1, 'medium' => 1, 'low' => 1],
            );
        }

        if (isset($data['business_hours_days'])) {
            $data['business_hours_days'] = array_values(array_unique(array_map('intval', $data['business_hours_days'])));
        }

        if (isset($data['sla_hours'])) {
            // 只接受已知优先级，避免脏键写进配置
            $data['sla_hours'] = array_merge(
                Settings::get('sla_hours'),
                array_intersect_key($data['sla_hours'], Settings::DEFAULTS['sla_hours']),
            );
        }

        $changed = array_keys($data);
        Settings::put($data);

        AuditLog::record('settings_updated', null, [
            'keys' => array_values(array_diff($changed, self::SECRETS)),
            'secrets_changed' => array_values(array_intersect($changed, self::SECRETS)),
        ]);

        return response()->json(Settings::all());
    }

    /** 发送测试短信，验证当前平台配置是否可用 */
    public function testSms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'regex:/^1[3-9]\d{9}$/'],
        ]);

        try {
            SmsManager::sendTest($data['phone']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => '测试短信已发送，请查收']);
    }
}
