<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketLogResource;
use App\Models\TicketLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TicketLogController extends Controller
{
    /** 操作审计列表（staff）：按动作/操作人/工单筛选，分页 */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TicketLog::query()
            ->with(['user:id,name,role', 'ticket:id,no,title'])
            ->latest();

        $query->when($request->input('action'), fn ($q, $v) => $q->where('action', $v))
            ->when($request->input('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->input('keyword'), function ($q, $v) {
                $q->whereHas('ticket', fn ($t) => $t->where('no', 'like', "%{$v}%")->orWhere('title', 'like', "%{$v}%"));
            });

        return TicketLogResource::collection(
            $query->paginate($request->integer('per_page', 20))
        )->additional([
            'meta.actions' => TicketLog::distinct()->pluck('action')->values(),
        ]);
    }
}
