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
        // 【修正】使用單數 'coach' 表名，解決 PostgreSQL 大小寫問題。
        Schema::create('coaches', function (Blueprint $table) { 
            $table->uuid('coach_id')->primary();
            $table->string('name');
            
            // 合併自後續 migration (add_contact_to_coaches_table.php)
            $table->string('phone')->nullable();
            $table->string('email')->unique();
            $table->boolean('is_active')->default(true);
            
            // 合併自後續 migration (add_member_id_to_coaches_table.php)
            // NOTE: 'member' 表名必須是單數
            $table->uuid('member_id')->nullable(); 
            $table->foreign('member_id')->references('member_id')->on('members')->onDelete('set null'); 
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 【修正】使用單數 'coach'
        Schema::dropIfExists('coaches');
    }
};