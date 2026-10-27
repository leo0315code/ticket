<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WebSocket 一次性连接票据
 *
 * 长期 Sanctum token 走 URL 会落进 nginx 日志/浏览器历史，改为连 WS 前先换一张
 * 60 秒有效、只能使用一次的 ticket，网关校验后立即作废。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ws_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ws_tickets');
    }
};
