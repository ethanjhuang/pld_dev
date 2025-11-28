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
        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('booking_id')->primary();
            $table->uuid('member_id')->constrained('members', 'member_id'); // 預約的家長
            $table->uuid('course_id')->constrained('courses', 'course_id');
            $table->uuid('child_id')->nullable()->constrained('children', 'child_id'); // 實際參加的兒童 (可為 NULL，供訪客使用)
            
            $table->string('status'); // CONFIRMED, WAITING, CANCELLED, ATTENDED, NO_SHOW
            $table->decimal('points_deducted', 10, 2);
            $table->integer('waiting_list_rank')->nullable();
            $table->boolean('is_paid'); // 是否已實際扣點
            $table->string('guest_child_name')->nullable();
            $table->timestamp('cancellation_time')->nullable();

            $table->timestamps();
            
            // 確保同一個課程不能被同一個 child_id 重複預約 (不考慮訪客，因為訪客沒有 child_id)
            $table->unique(['course_id', 'child_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking');
    }
};
