<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, $request->integer('per_page', 20)));
        $page = max(1, $request->integer('page', 1));
        $paginator = $request->user()->notifications()->paginate($perPage, ['*'], 'page', $page);

        $notifications = collect($paginator->items())->map(fn ($n) => [
            'id' => $n->id,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at->format('m-d H:i'),
            ...$n->data,
        ]);

        return response()->json([
            'unread' => $request->user()->unreadNotifications()->count(),
            'data' => $notifications,
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'string']]);
        $query = $request->user()->unreadNotifications();
        if ($data['id'] ?? null) {
            $query->where('id', $data['id']);
        }
        $query->update(['read_at' => now()]);

        return response()->json(['message' => '已标记为已读']);
    }
}
