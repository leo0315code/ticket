<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = TicketRule::with('category:id,name')->orderBy('sort')->orderBy('id')->get()
            ->map(fn (TicketRule $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'keywords' => $r->keywords,
                'set_category_id' => $r->set_category_id,
                'set_category' => $r->category?->name,
                'set_priority' => $r->set_priority,
                'is_active' => $r->is_active,
                'sort' => $r->sort,
            ]);

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = TicketRule::create($this->validated($request));

        return response()->json(['id' => $rule->id], 201);
    }

    public function update(Request $request, TicketRule $ticketRule): JsonResponse
    {
        $ticketRule->update($this->validated($request));

        return response()->json(['message' => '已更新']);
    }

    public function destroy(TicketRule $ticketRule): JsonResponse
    {
        $ticketRule->delete();

        return response()->json(['message' => '已删除']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'keywords' => ['required', 'string', 'max:500'],
            'set_category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'set_priority' => ['nullable', Rule::in(Ticket::PRIORITIES)],
            'is_active' => ['boolean'],
            'sort' => ['integer', 'min:0', 'max:9999'],
        ]);
    }
}
