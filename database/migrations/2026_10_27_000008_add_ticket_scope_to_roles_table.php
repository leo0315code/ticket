<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // 工单数据范围：全部 / 仅我受理的 / 仅我提交的
            $table->string('ticket_scope', 20)->default('all')->after('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('ticket_scope');
        });
    }
};
