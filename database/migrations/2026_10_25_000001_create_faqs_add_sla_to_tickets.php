<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 200);
            $table->text('answer');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('sla_due_at')->nullable()->after('closed_at');
            $table->timestamp('first_response_at')->nullable()->after('sla_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['sla_due_at', 'first_response_at']);
        });
        Schema::dropIfExists('faqs');
    }
};
