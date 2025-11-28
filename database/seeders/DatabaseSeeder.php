<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeders.
     */
    public function run(): void
    {
        // 1. 系統設定 (必須最優先載入)
        $this->call(SystemConfigSeeder::class);
        
        // 2. 初始數據 (Admin, Test Coach, Test Classroom)
        $this->call(InitialDataSeeder::class); 
        
        // 3. 測試用課程數據 (如果需要)
        // $this->call(TestCourseSeeder::class);
    }
}