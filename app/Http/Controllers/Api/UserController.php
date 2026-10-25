<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** 用户管理列表（admin） */
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::withCount(['tickets', 'assignedTickets'])->orderBy('id')->get()
        );
    }

    /** 新建账号（admin；可建客服/管理员/普通用户） */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'username' => ['required', 'string', 'max:30', 'unique:users,username', 'regex:/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]{2,30}$/u'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        $user = User::create($data);

        return response()->json(new UserResource($user), 201);
    }

    /** 重置密码：生成随机密码，仅在响应中返回一次 */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $password = Str::random(12);

        $user->update(['password' => $password]);
        // 使该用户全部 token 失效
        $user->tokens()->delete();

        return response()->json([
            'message' => '密码已重置',
            'password' => $password, // 一次性明文，仅本次响应
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        if ($user->id === $request->user()->id && $data['role'] !== 'admin') {
            return response()->json(['message' => '不能降级自己的管理员角色'], 422);
        }

        $user->update($data);

        return response()->json(new UserResource($user));
    }
}
