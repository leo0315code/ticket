<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->orderByDesc('id');

        if ($action = $request->string('action')->toString()) {
            $query->where('action', $action);
        }
        if ($keyword = $request->string('keyword')->toString()) {
            $query->where(fn ($q) => $q->where('actor_name', 'like', "%{$keyword}%")
                ->orWhere('target_label', 'like', "%{$keyword}%"));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        return response()->json($query->paginate($perPage));
    }
}
