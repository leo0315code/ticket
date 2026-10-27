<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        if (! Settings::get('allow_register', true)) {
            return response()->json(['message' => '注册已关闭，请联系管理员开通账号'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            // 账号：2-30 位中文、字母、数字、下划线
            'username' => ['required', 'string', 'max:30', 'unique:users,username', 'regex:/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]{2,30}$/u'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
        ]);

        $user = User::create([...$data, 'role' => 'user']);
        $token = $user->createToken('spa')->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            // 账号可以是用户名或邮箱
            'account' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited($request);

        $user = User::where('username', $credentials['account'])
            ->orWhere('email', $credentials['account'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => '账号或密码不正确'], 401);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => '账号已停用，请联系管理员'], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['token' => $user->createToken('spa')->plainTextToken, 'user' => new UserResource($user)]);
    }

    /** 后台登录：仅 staff，且必须通过滑块验证（一次性票据） */
    public function adminLogin(Request $request): JsonResponse
    {
        $this->ensureIsNotRateLimited($request);

        $credentials = $request->validate([
            'account' => ['required', 'string'],
            'password' => ['required', 'string'],
            'captcha_ticket' => ['required', 'string'],
        ]);

        // 核销滑块票据（一次性）
        if (! Cache::pull('captcha_ticket:'.$credentials['captcha_ticket'])) {
            return response()->json(['message' => '请先完成滑块验证'], 422);
        }

        $user = User::where('username', $credentials['account'])
            ->orWhere('email', $credentials['account'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => '账号或密码不正确'], 401);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => '账号已停用，请联系管理员'], 403);
        }

        if (! $user->isStaff()) {
            return response()->json(['message' => '该账号是普通用户，请使用用户端登录'], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['token' => $user->createToken('spa')->plainTextToken, 'user' => new UserResource($user)]);
    }

    private function ensureIsNotRateLimited(Request $request): void
    {
        $max = (int) config('auth.login_rate_limit', 10);

        if (RateLimiter::tooManyAttempts($this->throttleKey($request), $max)) {
            $seconds = RateLimiter::availableIn($this->throttleKey($request));
            abort(429, "尝试过于频繁，请 {$seconds} 秒后重试");
        }

        RateLimiter::hit($this->throttleKey($request));
    }

    private function throttleKey(Request $request): string
    {
        return Str::lower($request->input('account')).'|'.$request->ip();
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => '已退出登录']);
    }

    /** 忘记密码：账号（用户名/邮箱）→ 已绑定邮箱则发送重置链接，未绑定则提示联系管理员 */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['account' => ['required', 'string', 'max:100']]);
        $account = trim($data['account']);

        $user = User::where('username', $account)->orWhere('email', $account)->first();

        // 账号不存在或没绑邮箱也返回同一句话，避免被用来探测账号
        if ($user && filled($user->email)) {
            try {
                PasswordBroker::sendResetLink(['email' => $user->email]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => '如果该账号绑定了邮箱，重置链接已发送，请查收（含垃圾邮件箱）。未绑定邮箱的账号请联系管理员重置。',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = PasswordBroker::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            // 改密即踢掉所有旧登录
            $user->tokens()->delete();
        });

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => '密码已重置，请用新密码登录']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    /** 修改个人资料：昵称、邮箱（选填，仅用于邮件通知） */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($data);

        return response()->json(['user' => new UserResource($user->fresh())]);
    }
}
