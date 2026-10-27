<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    /** 角色列表 + 权限目录 */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Role::withCount('users')->orderBy('id')->get()->map(fn (Role $role) => $this->payload($role)),
            'catalog' => Permissions::catalog(),
        ]);
    }

    /** 下拉选项：用户管理换角色时用，不需要完整权限 */
    public function options(): JsonResponse
    {
        return response()->json(
            Role::orderBy('id')->get(['name', 'label'])
                ->map(fn (Role $role) => ['name' => $role->name, 'label' => $role->label])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:30', 'regex:/^[a-z][a-z0-9_]{1,29}$/', 'unique:roles,name'],
            'label' => ['required', 'string', 'max:30'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::keys())],
        ], [
            'name.regex' => '角色标识只能用小写字母开头，含字母、数字、下划线',
        ]);

        $role = Role::create([...$data, 'is_system' => false]);

        return response()->json($this->payload($role), 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->name === 'admin') {
            return response()->json(['message' => '管理员角色固定拥有全部权限，不能修改'], 422);
        }

        $data = $request->validate([
            'label' => ['required', 'string', 'max:30'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::keys())],
        ]);

        $role->update($data);

        return response()->json($this->payload($role->fresh()));
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json(['message' => '内置角色不能删除'], 422);
        }
        if ($role->users()->exists()) {
            return response()->json(['message' => '还有用户使用该角色，请先调整这些用户的角色'], 422);
        }

        $role->delete();

        return response()->json(['message' => '角色已删除']);
    }

    private function payload(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->label,
            'permissions' => $role->permissions,
            'is_system' => $role->is_system,
            'permission_count' => $role->permissionCount(),
            'users_count' => $role->users_count ?? $role->users()->count(),
        ];
    }
}
