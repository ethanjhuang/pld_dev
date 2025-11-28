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
        Schema::create('point_logs', function (Blueprint $table) {
            $table->uuid('log_id')->primary();
            $table->uuid('membership_id')->constrained('membership_cards', 'card_id');
            $table->decimal('change_amount', 10, 2);
            $table->string('change_type'); // BOOKING_DEDUCT, TOP_UP, CANCELLATION_REFUND, TRANSFER_IN/OUT
            $table->uuid('related_id')->nullable(); // 連結到 Transaction ID 或 Booking ID
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_log');
    }
};
