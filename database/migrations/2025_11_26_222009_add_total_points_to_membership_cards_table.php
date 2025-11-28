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
        Schema::table('membership_cards', function (Blueprint $table) {
            // 新增欄位：記錄會員卡最初購買的總點數
            $table->decimal('total_points', 8, 2)->default(0.00)->after('expiry_date'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_cards', function (Blueprint $table) {
            $table->dropColumn('total_points');
        });
    }
};