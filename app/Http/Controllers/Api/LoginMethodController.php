<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Settings;
use App\Support\Sms\SmsManager;
use App\Support\WeChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * 备用登录方式：手机号验证码、微信公众号扫码。
 * 手机号未注册时自动建号（受「开放注册」开关控制）；微信仍需先绑定已有账号。
 */
class LoginMethodController extends Controller
{
    public const PHONE_RULE = ['required', 'regex:/^1[3-9]\d{9}$/'];

    /* ---------------- 手机号验证码登录 ---------------- */

    public function phoneCode(Request $request): JsonResponse
    {
        $this->ensurePhoneLoginEnabled();

        $data = $request->validate(['phone' => self::PHONE_RULE]);

        try {
            $ttl = SmsManager::sendCode($data['phone'], 'login');
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), '频繁') || str_contains($e->getMessage(), '上限') ? 429 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        return response()->json(['message' => '验证码已发送', 'ttl' => $ttl]);
    }

    public function phoneLogin(Request $request): JsonResponse
    {
        $this->ensurePhoneLoginEnabled();

        $data = $request->validate([
            'phone' => self::PHONE_RULE,
            'code' => ['required', 'digits:6'],
        ]);

        if (! SmsManager::verify($data['phone'], 'login', $data['code'])) {
            return response()->json(['message' => '验证码错误或已过期'], 422);
        }

        $user = User::where('phone', $data['phone'])->first();

        // 手机号没注册过就直接建号：验证码已证明手机号归属，等同于完成注册
        if (! $user) {
            if (! Settings::get('allow_register')) {
                return response()->json(['message' => '注册已关闭，请联系管理员开通账号'], 403);
            }

            $user = User::create([
                'name' => '用户'.substr($data['phone'], -4),
                'username' => $this->freeUsername($data['phone']),
                'phone' => $data['phone'],
                'password' => \Illuminate\Support\Str::random(24),
                'role' => 'user',
            ]);
        }

        return $this->issueToken($user);
    }

    /* ---------------- 微信公众号 / 开放平台扫码 ---------------- */

    public function wechatUrl(): JsonResponse
    {
        if (! WeChat::enabled()) {
            return response()->json(['message' => '微信登录未启用，请联系管理员'], 403);
        }

        $state = WeChat::state();

        return response()->json([
            'url' => WeChat::authorizeUrl($state, WeChat::callbackUrl()),
            'state' => $state,
        ]);
    }

    public function wechatLogin(Request $request): JsonResponse
    {
        if (! WeChat::enabled()) {
            return response()->json(['message' => '微信登录未启用，请联系管理员'], 403);
        }

        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        if (! WeChat::stateValid($data['state'])) {
            return response()->json(['message' => '登录已过期，请重新扫码'], 422);
        }

        try {
            $identity = WeChat::identityByCode($data['code']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user = User::where('wechat_openid', $identity['openid'])->first();
        if (! $user) {
            return response()->json(['message' => '该微信还未绑定账号，请先用密码登录后在「账号设置」中绑定'], 422);
        }

        return $this->issueToken($user);
    }

    /* ---------------- 绑定 / 解绑 ---------------- */

    public function bindPhoneCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => array_merge(self::PHONE_RULE, [Rule::unique('users', 'phone')->ignore($request->user()->id)]),
        ]);

        try {
            $ttl = SmsManager::sendCode($data['phone'], 'bind');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => '验证码已发送', 'ttl' => $ttl]);
    }

    public function bindPhone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => array_merge(self::PHONE_RULE, [Rule::unique('users', 'phone')->ignore($request->user()->id)]),
            'code' => ['required', 'digits:6'],
        ]);

        if (! SmsManager::verify($data['phone'], 'bind', $data['code'])) {
            return response()->json(['message' => '验证码错误或已过期'], 422);
        }

        $user = $request->user();
        $user->forceFill(['phone' => $data['phone']])->save();

        return response()->json(['message' => '手机号已绑定', 'user' => new UserResource($user)]);
    }

    public function bindWechat(Request $request): JsonResponse
    {
        if (! WeChat::enabled()) {
            return response()->json(['message' => '微信登录未启用，请联系管理员'], 403);
        }

        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        if (! WeChat::stateValid($data['state'])) {
            return response()->json(['message' => '操作已过期，请重新扫码'], 422);
        }

        try {
            $identity = WeChat::identityByCode($data['code']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $taken = User::where('wechat_openid', $identity['openid'])
            ->whereKeyNot($request->user()->id)
            ->exists();
        if ($taken) {
            return response()->json(['message' => '该微信已绑定其他账号'], 422);
        }

        $user = $request->user();
        $user->forceFill([
            'wechat_openid' => $identity['openid'],
            'wechat_unionid' => $identity['unionid'],
            'wechat_nickname' => $identity['nickname'],
            'wechat_avatar' => $identity['avatar'],
        ])->save();

        return response()->json(['message' => '微信已绑定', 'user' => new UserResource($user)]);
    }

    public function unbindWechat(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill([
            'wechat_openid' => null,
            'wechat_unionid' => null,
            'wechat_nickname' => null,
            'wechat_avatar' => null,
        ])->save();

        return response()->json(['message' => '微信已解绑']);
    }

    /* ---------------- 内部 ---------------- */

    /** 手机号建号时生成一个不冲突的登录名，如 p13800138000 */
    private function freeUsername(string $phone): string
    {
        $base = 'p'.$phone;
        $username = $base;
        $i = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base.'_'.$i++;
        }

        return $username;
    }

    private function issueToken(User $user): JsonResponse
    {
        if (! $user->isActive()) {
            return response()->json(['message' => '账号已停用，请联系管理员'], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    private function ensurePhoneLoginEnabled(): void
    {
        abort_unless((bool) Settings::get('phone_login_enabled'), 403, '手机号登录未启用，请联系管理员');
    }
}
