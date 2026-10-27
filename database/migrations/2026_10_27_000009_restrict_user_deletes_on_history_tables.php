<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 历史数据的 user_id 不再随用户删除而级联清除：
 * 工单、回复、附件、操作日志都保留，删除用户改为被数据库拒绝（restrict），
 * 需要“注销”时用停用账号，而不是物理删除。
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tickets', 'ticket_replies', 'attachments', 'ticket_logs'] as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['tickets', 'ticket_replies', 'attachments', 'ticket_logs'] as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
