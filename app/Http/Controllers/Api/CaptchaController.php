<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 滑块行为验证：签发一次性票据
 * 流程：GET /captcha/issue → 前端滑块完成 → POST /captcha/verify {key} → ticket
 *       后台登录携带 ticket，服务端核销（一次性，5 分钟有效）
 */
class CaptchaController extends Controller
{
    /** 签发验证 key（滑块会话） */
    public function issue(): JsonResponse
    {
        $key = (string) Str::uuid();
        Cache::put("captcha:{$key}", true, now()->addMinutes(5));

        return response()->json(['key' => $key]);
    }

    /** 滑块完成后核销，换取一次性登录票据 */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string']]);

        // key 必须存在且只能核销一次
        if (! Cache::pull("captcha:{$data['key']}")) {
            return response()->json(['message' => '验证已失效，请重试'], 422);
        }

        $ticket = (string) Str::uuid();
        Cache::put("captcha_ticket:{$ticket}", true, now()->addMinutes(5));

        return response()->json(['ticket' => $ticket]);
    }
}
