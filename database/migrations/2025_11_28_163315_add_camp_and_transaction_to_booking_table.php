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
        // 假設 bookings 表名為單數 'bookings'
        Schema::table('bookings', function (Blueprint $table) {
            // 新增 camp_id 欄位 (允許為空，因為也可以是 course 預約)
            $table->uuid('camp_id')->nullable()->after('course_id');
            // 新增 transaction_id 欄位 (允許為空，因為 course 預約不使用它)
            $table->uuid('transaction_id')->nullable()->after('camp_id');

            // 建立外鍵約束
            // 指向我們最終確認的 'camps' 表格
            $table->foreign('camp_id')->references('camp_id')->on('camps')->onDelete('set null');
            // 假設 Transaction 表名為單數 'transactions'
            $table->foreign('transaction_id')->references('transaction_id')->on('transactions')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['camp_id']);
            $table->dropForeign(['transaction_id']);
            $table->dropColumn(['camp_id', 'transaction_id']);
        });
    }
};