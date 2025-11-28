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
        Schema::create('courses', function (Blueprint $table) {
            $table->uuid('course_id')->primary();
            $table->uuid('coach_id')->constrained('coaches', 'coach_id');
            $table->uuid('classroom_id')->constrained('classrooms', 'classroom_id');
            $table->uuid('series_id')->nullable()->constrained('course_series', 'series_id');
            $table->uuid('camp_id')->nullable(); // V1.1 營隊表，FK暫不建立
            
            $table->string('name');
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->integer('max_capacity');
            $table->integer('min_capacity');
            $table->integer('current_bookings')->default(0);
            $table->boolean('is_confirmed')->default(false);
            $table->decimal('required_points', 10, 2);
            $table->string('min_child_level')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course');
    }
};
