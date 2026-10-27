<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Settings;
use App\Support\WeChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 公众号扫码登录（PC 端）：
 * 1. 电脑生成二维码，内容是公众号网页授权地址（state 里带 scene）
 * 2. 手机微信扫码打开 → 微信内完成授权 → 回调页把 code+scene 交给 confirm
 * 3. 电脑轮询 status，拿到 token 后登录
 *
 * 全程不需要微信开放平台的“网站应用”，只要认证服务号 + 网页授权域名。
 */
class WeChatQrController extends Controller
{
    private const TTL = 300;

    /** 生成扫码会话 */
    public function start(): JsonResponse
    {
        if (! WeChat::enabled()) {
            return response()->json(['message' => '微信登录未开启或未配置 AppID/AppSecret'], 422);
        }
        if (Settings::get('wechat_mode') !== 'mp') {
            return response()->json(['message' => '当前是开放平台模式，电脑端可直接弹二维码，无需扫码授权'], 422);
        }

        $scene = Str::random(32);
        Cache::put($this->key($scene), ['status' => 'pending'], self::TTL);

        return response()->json([
            'scene' => $scene,
            'url' => WeChat::authorizeUrl('qr_'.$scene, WeChat::callbackUrl()),
            'expires_in' => self::TTL,
        ]);
    }

    /** 手机端授权完成：code 换身份，把登录态塞回扫码会话 */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scene' => ['required', 'string', 'size:32'],
            'code' => ['required', 'string', 'max:256'],
        ]);

        $key = $this->key($data['scene']);
        if (! Cache::has($key)) {
            return response()->json(['message' => '二维码已过期，请在电脑上刷新后重试'], 422);
        }

        try {
            $identity = WeChat::identityByCode($data['code']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user = User::query()
            ->where('wechat_openid', $identity['openid'])
            ->when($identity['unionid'], fn ($q) => $q->orWhere('wechat_unionid', $identity['unionid']))
            ->first();

        if (! $user) {
            return response()->json([
                'message' => '该微信还没有绑定账号，请先在手机或电脑上用手机号/账号登录，并在「账号设置」里绑定微信',
            ], 422);
        }
        if (! $user->isActive()) {
            return response()->json(['message' => '账号已停用，请联系管理员'], 403);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'wechat_nickname' => $identity['nickname'] ?: $user->wechat_nickname,
            'wechat_avatar' => $identity['avatar'] ?: $user->wechat_avatar,
        ])->save();

        Cache::put($key, [
            'status' => 'ok',
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => (new UserResource($user))->resolve(),
        ], self::TTL);

        return response()->json(['message' => '登录成功，请回到电脑端继续']);
    }

    /** 电脑端轮询 */
    public function status(string $scene): JsonResponse
    {
        if (strlen($scene) !== 32) {
            return response()->json(['status' => 'expired']);
        }

        $key = $this->key($scene);
        $session = Cache::get($key);

        if (! $session) {
            return response()->json(['status' => 'expired']);
        }
        if ($session['status'] !== 'ok') {
            return response()->json(['status' => 'pending']);
        }

        // 一次性取走
        Cache::forget($key);

        return response()->json([
            'status' => 'ok',
            'token' => $session['token'],
            'user' => $session['user'],
        ]);
    }

    private function key(string $scene): string
    {
        return 'wechat_qr:'.$scene;
    }
}
