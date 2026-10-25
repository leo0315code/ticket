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
    public function index(): AnonymousResourceCollection
    {
        return FaqResource::collection(Faq::orderBy('sort')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:200'],
            'answer' => ['required', 'string', 'max:5000'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(new FaqResource(Faq::create($data)), 201);
    }

    public function update(Request $request, Faq $faq): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:200'],
            'answer' => ['required', 'string', 'max:5000'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);

        $faq->update($data);

        return response()->json(new FaqResource($faq));
    }

    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();

        return response()->json(['message' => '已删除']);
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
        ];
    }
}
