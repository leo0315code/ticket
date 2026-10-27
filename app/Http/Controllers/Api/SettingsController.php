<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /** 系统设置（admin） */
    public function index(): JsonResponse
    {
        $all = Settings::all();
        // AppSecret 不出库：只告诉前端“是否已设置”
        $all['wechat_app_secret'] = '';
        $all['wechat_app_secret_set'] = filled(Settings::get('wechat_app_secret'));

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
            'auto_close_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'reopen_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'reopen_limit' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'attach_max_mb' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'notify_mail' => ['sometimes', 'boolean'],

            'phone_login_enabled' => ['sometimes', 'boolean'],
            'sms_driver' => ['sometimes', 'in:log,http'],
            'sms_http_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'sms_sign' => ['sometimes', 'string', 'max:30'],
            'sms_code_ttl' => ['sometimes', 'integer', 'min:60', 'max:1800'],
            'wechat_login_enabled' => ['sometimes', 'boolean'],
            'wechat_mode' => ['sometimes', 'in:open,mp'],
            'wechat_app_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'wechat_app_secret' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        // 留空表示“不修改”，避免前端回显时把密钥清掉
        if (array_key_exists('wechat_app_secret', $data) && blank($data['wechat_app_secret'])) {
            unset($data['wechat_app_secret']);
        }

        if (isset($data['sla_hours'])) {
            // 只接受已知优先级，避免脏键写进配置
            $data['sla_hours'] = array_merge(
                Settings::get('sla_hours'),
                array_intersect_key($data['sla_hours'], Settings::DEFAULTS['sla_hours']),
            );
        }

        Settings::put($data);

        return response()->json(Settings::all());
    }
}
