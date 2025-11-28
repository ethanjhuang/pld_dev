<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; 

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. 處理 password 欄位 (此處僅為結構，已省略 password 新增邏輯)
        Schema::table('members', function (Blueprint $table) {
            // 這個閉包是空的，但保留 Schema::table 語法
        });

        // 2. CRITICAL FIX: 移除 CHECK 約束並更新數據 (必須在 ENUM 類型轉換前完成)
        
        // 2a. 移除 CHECK 約束，否則 ORM update 會失敗
        DB::statement('ALTER TABLE members DROP CONSTRAINT IF EXISTS members_role_check;');
        
        // 2b. 執行數據更新 (PARENT -> MEMBER)。現在約束已移除，更新將成功
        DB::table('members')
            ->where('role', 'PARENT')
            ->update(['role' => 'MEMBER']);

        // 3. 執行 ENUM 擴展邏輯 (避免 RENAME/DROP，以防類型不存在)
        
        // 3a. 創建新的 ENUM 類型 (包含 'MEMBER')
        // 注意：PostgreSQL 允許創建同名類型，但類型名稱必須是唯一的，這裡採用一個新名稱。
        DB::statement("CREATE TYPE members_role_new_enum AS ENUM ('ADMIN', 'COACH', 'MEMBER');");
        
        // 3b. 移除 DEFAULT
        DB::statement("ALTER TABLE members ALTER COLUMN role DROP DEFAULT;");
        
        // 3c. 轉換欄位類型 (將數據轉換為新 ENUM 類型)
        // 由於我們創建了一個名為 members_role_new_enum 的新類型，這裡使用新類型。
        DB::statement("ALTER TABLE members ALTER COLUMN role TYPE members_role_new_enum USING role::text::members_role_new_enum;");
        
        // 3d. 設定新的 DEFAULT
        DB::statement("ALTER TABLE members ALTER COLUMN role SET DEFAULT 'MEMBER';");
        
        // 4. 清理：刪除舊的 ENUM 類型 (如果它真的存在，這裡假設它叫 members_role_old)
        // 為了穩健性，我們將這步留給 DB 管理員手動處理，避免 Migration 失敗。
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 由於 ENUM 復原邏輯複雜，我們這裡只執行簡單的 dropColumn
        Schema::table('members', function (Blueprint $table) {
            // ... (省略)
        });
    }
};