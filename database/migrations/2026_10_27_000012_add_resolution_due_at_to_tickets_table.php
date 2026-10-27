<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 解决时限（SLA）独立于首次响应时限。
     * 历史工单不回填：旧数据的 created_at 已无法反推营业时间口径，回填反而会算出假合规率。
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('resolution_due_at')->nullable()->after('sla_due_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['resolution_due_at']);
            $table->dropColumn('resolution_due_at');
        });
    }
};
