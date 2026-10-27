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
        return response()->json(Settings::all());
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
        ]);

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
