<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('course_series', function (Blueprint $table) {
            $table->uuid('series_id')->primary();
            $table->string('name');
            $table->json('recurrence_pattern'); // 儲存 JSON 格式的循環模式
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_series');
    }
};
