<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CannedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CannedResponseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = CannedResponse::query()
            ->with('category:id,name')
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $kw = $request->string('keyword');
                $q->where(fn ($q) => $q->where('title', 'like', "%{$kw}%")->orWhere('body', 'like', "%{$kw}%"));
            })
            ->orderByDesc('uses')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'title', 'body', 'category_id', 'uses']);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $row = CannedResponse::create([...$data, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $row->load('category:id,name')], 201);
    }

    public function update(Request $request, CannedResponse $cannedResponse): JsonResponse
    {
        $cannedResponse->update($this->validated($request));

        return response()->json(['data' => $cannedResponse->fresh()->load('category:id,name')]);
    }

    public function destroy(CannedResponse $cannedResponse): JsonResponse
    {
        $cannedResponse->delete();

        return response()->json(['message' => '已删除']);
    }

    /** 使用计数 + 返回正文，方便前端一次调用完成插入 */
    public function touch(CannedResponse $cannedResponse): JsonResponse
    {
        $cannedResponse->increment('uses');

        return response()->json(['data' => ['id' => $cannedResponse->id, 'body' => $cannedResponse->body]]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);
    }
}
