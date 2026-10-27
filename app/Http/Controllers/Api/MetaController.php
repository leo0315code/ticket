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
            'phone_login_enabled' => (bool) Settings::get('phone_login_enabled'),
            'wechat_login_enabled' => (bool) Settings::get('wechat_login_enabled'),
            'wechat_mode' => (string) Settings::get('wechat_mode', 'open'),
        ]);
    }
}
