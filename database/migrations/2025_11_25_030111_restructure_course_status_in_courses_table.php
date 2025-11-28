<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // 1. 新增 status 欄位 (支援 SCHEDULED/CANCELLED/COMPLETED 等多狀態)
            $table->string('status')->default('SCHEDULED')->after('required_points');

            // 2. 移除冗餘的 is_confirmed 欄位
            $table->dropColumn('is_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // 撤銷時，移除 status 欄位並還原 is_confirmed 
            $table->dropColumn('status');
            $table->boolean('is_confirmed')->default(false);
        });
    }
};