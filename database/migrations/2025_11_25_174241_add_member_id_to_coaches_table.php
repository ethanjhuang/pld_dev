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
        Schema::table('coaches', function (Blueprint $table) {
            // 新增欄位：關聯到 members 表格的 member_id
            $table->uuid('member_id')->nullable()->after('coach_id');
            
            // 添加外鍵約束，確保數據一致性
            $table->foreign('member_id')
                  ->references('member_id')
                  ->on('members')
                  ->onDelete('cascade');
                  
            // 如果您希望 member_id 在 coaches 表中是唯一的 (1:1 關聯)
            $table->unique('member_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coaches', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropColumn('member_id');
        });
    }
};