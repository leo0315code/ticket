<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentController extends Controller
{
    /** 客服列表（用于工单指派下拉） */
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::staff()->orderBy('name')->get());
    }
}
