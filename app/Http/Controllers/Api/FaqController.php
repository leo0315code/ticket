<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class FaqController extends Controller
{
    /** 帮助中心列表：工作人员看全部，客户只看已公开的；支持关键词搜索 */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Faq::query()
            ->when(! $request->user()?->isStaff(), fn ($q) => $q->where('is_public', true))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $request->string('q'));

                if (\App\Support\Search::fulltextAvailable() && mb_strlen($term) >= 2) {
                    $q->whereRaw('MATCH(question, answer) AGAINST (? IN BOOLEAN MODE)', [$term]);
                } else {
                    $kw = '%'.$term.'%';
                    $q->where(fn ($q) => $q->where('question', 'like', $kw)->orWhere('answer', 'like', $kw));
                }
            })
            ->orderBy('category')
            ->orderBy('sort')
            ->orderBy('id');

        return FaqResource::collection($query->get());
    }

    /** 阅读计数（展开答案时上报，用于识别“没人看”的条目） */
    public function view(Faq $faq): JsonResponse
    {
        $faq->increment('views');

        return response()->json(['views' => $faq->views]);
    }

    /** “有帮助 / 没帮助”反馈 */
    public function feedback(Request $request, Faq $faq): JsonResponse
    {
        $data = $request->validate(['helpful' => ['required', 'boolean']]);
        $faq->increment($data['helpful'] ? 'helpful_yes' : 'helpful_no');

        return response()->json([
            'helpful_yes' => $faq->helpful_yes,
            'helpful_no' => $faq->helpful_no,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(new FaqResource(Faq::create($this->validated($request))), 201);
    }

    public function update(Request $request, Faq $faq): JsonResponse
    {
        $faq->update($this->validated($request));

        return response()->json(new FaqResource($faq));
    }

    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();

        return response()->json(['message' => '已删除']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'question' => ['required', 'string', 'max:200'],
            'answer' => ['required', 'string', 'max:5000'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'category' => ['nullable', 'string', 'max:40'],
            'is_public' => ['sometimes', 'boolean'],
        ]);
    }
}

class FaqResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'answer' => $this->answer,
            'sort' => $this->sort,
            'category' => $this->category,
            'is_public' => (bool) $this->is_public,
            'views' => (int) $this->views,
            'helpful_yes' => (int) $this->helpful_yes,
            'helpful_no' => (int) $this->helpful_no,
        ];
    }
}
