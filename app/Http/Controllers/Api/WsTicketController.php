<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 签发 WebSocket 一次性连接票据
 *
 * 前端连 WS 前调用（带 Bearer token），拿到 ticket 后连
 * wss://host/ws?ticket=xxx；网关校验并作废，长期 token 不再出现在 URL 里。
 */
class WsTicketController extends Controller
{
    /** 票据有效期（秒） */
    private const TTL = 60;

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $ticket = Str::random(48);

        // 顺手清理过期票据，避免表无限增长
        DB::table('ws_tickets')->where('expires_at', '<', now()->subMinutes(10))->delete();

        DB::table('ws_tickets')->insert([
            'user_id' => $user->id,
            'ticket_hash' => hash('sha256', $ticket),
            'expires_at' => now()->addSeconds(self::TTL),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ticket' => $ticket,
            'expires_in' => self::TTL,
        ]);
    }
}
