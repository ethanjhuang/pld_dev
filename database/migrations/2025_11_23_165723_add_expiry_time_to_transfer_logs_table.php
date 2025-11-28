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
        Schema::table('transfer_logs', function (Blueprint $table) {
            // 新增鎖定點數的到期時間
            $table->timestamp('expiry_time')->nullable()->after('status')->comment('鎖定點數的到期時間，過期將由排程自動回滾。');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transfer_logs', function (Blueprint $table) {
            $table->dropColumn('expiry_time');
        });
    }
};