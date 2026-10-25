<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
