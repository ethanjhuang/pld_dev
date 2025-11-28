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
        // 核心修正：使用單數 'camp' 表名，解決 PostgreSQL 命名衝突
        Schema::create('camps', function (Blueprint $table) {
            $table->uuid('camp_id')->primary();
            
            // 營隊基礎資訊
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            
            // 計費與名額 (情境 3, 4)
            $table->decimal('price', 10, 2)->comment('營隊價格 (金錢計費)'); // 金錢計費
            $table->integer('max_capacity');
            $table->integer('current_bookings')->default(0)->comment('當前已預約/鎖定人數');

            // 時間與排程 (情境 1, 2)
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time')->nullable()->comment('每日固定開始時間');
            $table->time('end_time')->nullable()->comment('每日固定結束時間');
            
            // 資源與衝突檢查 (情境 5)
            // NOTE: 'coach' 和 'classroom' 表名必須也是單數
            $table->uuid('coach_id')->nullable();
            $table->uuid('classroom_id')->nullable();
            
            // 退費與行政 (情境 3-1)
            $table->jsonb('cancellation_policy')->nullable()->comment('階梯式退款規則 JSON');
            $table->datetime('registration_start_date')->nullable();
            $table->datetime('registration_end_date')->nullable();
            
            $table->timestamps();

            // 外鍵約束 (確保 'coach' 和 'classroom' 表名是單數)
            $table->foreign('coach_id')->references('coach_id')->on('coaches')->onDelete('set null');
            $table->foreign('classroom_id')->references('classroom_id')->on('classrooms')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('camps');
    }
};