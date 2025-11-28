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
            // 新增電話和郵箱欄位
            $table->string('phone', 20)->nullable()->after('bio');
            $table->string('email')->nullable()->unique()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coaches', function (Blueprint $table) {
            // 撤銷時刪除新增的欄位
            $table->dropColumn(['phone', 'email']);
        });
    }
};