<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

class MetaController extends Controller
{
    /** 公开元信息：登录页/上传组件需要，未登录可访问 */
    public function show(): JsonResponse
    {
        return response()->json([
            'site_name' => Settings::get('site_name'),
            'allow_register' => (bool) Settings::get('allow_register'),
            'attach_max_mb' => (int) Settings::get('attach_max_mb'),
            'attach_max_count' => (int) Settings::get('attach_max_count'),
            'phone_login_enabled' => (bool) Settings::get('phone_login_enabled'),
            'wechat_login_enabled' => (bool) Settings::get('wechat_login_enabled'),
            'wechat_mode' => (string) Settings::get('wechat_mode', 'open'),
            // 服务时间：帮助中心与提单页展示/预拦截用
            'support_phone' => (string) Settings::get('support_phone'),
            'work_hours_enabled' => (bool) Settings::get('business_hours_enabled'),
            'work_days' => implode(',', (array) Settings::get('business_hours_days', [1, 2, 3, 4, 5])),
            'work_hours_start' => (string) Settings::get('business_hours_start', '09:00'),
            'work_hours_end' => (string) Settings::get('business_hours_end', '18:00'),
            'agents_online' => \App\Models\User::onlineStaff()->exists(),
            'require_online_agent' => (bool) Settings::get('require_online_agent'),
        ]);
    }
}
