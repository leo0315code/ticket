<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::with('defaultAgent:id,name')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:categories,name'],
            'description' => ['nullable', 'string', 'max:200'],
            'default_agent_id' => ['nullable', Rule::exists('users', 'id')->where('is_active', true)],
        ]);

        $category = Category::create($data);

        return response()->json(new CategoryResource($category->load('defaultAgent:id,name')), 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('categories')->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:200'],
            'default_agent_id' => ['nullable', Rule::exists('users', 'id')->where('is_active', true)],
        ]);

        $category->update($data);

        return response()->json(new CategoryResource($category->load('defaultAgent:id,name')));
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();

        return response()->json(['message' => '分类已删除']);
    }
}
