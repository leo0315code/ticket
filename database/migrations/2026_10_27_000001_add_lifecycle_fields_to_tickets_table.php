<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->softDeletes();
            $table->string('closed_reason', 30)->nullable()->after('closed_at');
            $table->unsignedSmallInteger('reopen_count')->default(0)->after('closed_reason');
            $table->timestamp('last_activity_at')->nullable()->after('reopen_count');
        });

        // 历史数据：以更新时间作为最近活动时间
        DB::table('tickets')->update(['last_activity_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['closed_reason', 'reopen_count', 'last_activity_at']);
        });
    }
};
