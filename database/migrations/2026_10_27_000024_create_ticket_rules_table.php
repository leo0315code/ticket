<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('keywords', 500)->comment('逗号分隔，任一命中即触发');
            $table->foreignId('set_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('set_priority', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_rules');
    }
};
