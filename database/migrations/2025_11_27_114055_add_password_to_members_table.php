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
        Schema::table('members', function (Blueprint $table) {
            // 新增 password 欄位，用於滿足 Authenticatable 契約
            // 由於採用 OAuth 登入，這個欄位會存放不可逆的隨機雜湊值
            $table->string('password')->nullable()->after('email'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};