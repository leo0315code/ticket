<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('question');
            $table->boolean('is_public')->default(true)->after('answer');
            $table->unsignedInteger('views')->default(0)->after('is_public');
            $table->unsignedInteger('helpful_yes')->default(0)->after('views');
            $table->unsignedInteger('helpful_no')->default(0)->after('helpful_yes');
        });
    }

    public function down(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->dropColumn(['category', 'is_public', 'views', 'helpful_yes', 'helpful_no']);
        });
    }
};
