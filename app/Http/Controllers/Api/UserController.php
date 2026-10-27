<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /** 用户管理列表：搜索 / 角色 / 启用状态筛选 + 分页 */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::withCount(['tickets', 'assignedTickets'])->orderBy('id');

        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                ->orWhere('username', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }
        if ($role = (string) $request->query('role')) {
            $query->where('role', $role);
        }
        if ($request->query('is_active') !== null && $request->query('is_active') !== '') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min(100, max(1, $request->integer('per_page', 20)));

        return UserResource::collection($query->paginate($perPage));
    }

    /** 新建账号（可指定任意角色，含自定义角色） */
    /** 用户详情：基本信息 + 工单概要 + 最近工单（管理员视角） */
    public function show(User $user): JsonResponse
    {
        $submitted = $user->tickets();
        $assigned = $user->assignedTickets();
        $active = ['open', 'in_progress', 'pending_resolved'];
        $fields = ['id', 'no', 'title', 'status', 'priority', 'created_at'];

        return response()->json([
            'user' => new UserResource($user),
            'stats' => [
                'submitted' => (clone $submitted)->count(),
                'submitted_active' => (clone $submitted)->whereIn('status', $active)->count(),
                'assigned' => (clone $assigned)->count(),
                'assigned_active' => (clone $assigned)->whereIn('status', $active)->count(),
            ],
            'recent_submitted' => (clone $submitted)->latest()->limit(5)->get($fields),
            'recent_assigned' => (clone $assigned)->latest()->limit(5)->get($fields),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'username' => ['required', 'string', 'max:30', 'unique:users,username', 'regex:/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]{2,30}$/u'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', Rule::exists('roles', 'name')],
        ]);

        $user = User::create($data);

        return response()->json(new UserResource($user), 201);
    }

    /** 改资料 / 角色 / 启停用 / 重置密码 */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:100', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'string', Rule::exists('roles', 'name')],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', Password::min(8)],
        ]);

        $actor = $request->user();

        if ($user->id === $actor->id) {
            if (isset($data['role']) && $data['role'] !== $actor->role) {
                return response()->json(['message' => '不能修改自己的角色'], 422);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                return response()->json(['message' => '不能停用自己的账号'], 422);
            }
        }

        if (isset($data['role']) && $data['role'] !== $user->role
            && $user->isAdmin() && User::where('role', 'admin')->count() <= 1) {
            return response()->json(['message' => '至少要保留一名管理员'], 422);
        }

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            // 停用即踢下线，旧 token 立即失效
            $user->tokens()->delete();
        }

        $user->update($data);

        return response()->json(new UserResource($user->fresh()));
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
}
