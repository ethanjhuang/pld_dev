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
        Schema::table('courses', function (Blueprint $table) {
            // 新增欄位：目標受眾 (CHILD=兒童, ADULT=家長/成人)
            $table->enum('target_audience', ['CHILD', 'ADULT'])
                  ->default('CHILD')
                  ->after('min_child_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('target_audience');
        });
    }
};