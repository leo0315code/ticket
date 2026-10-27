<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 50)->default('');
            $table->string('action', 40)->index();
            $table->string('target_type', 40)->default('');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label', 120)->default('');
            $table->json('meta')->nullable();
            $table->string('ip', 45)->default('');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
