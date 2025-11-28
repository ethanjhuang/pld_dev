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
        Schema::create('transfer_logs', function (Blueprint $table) {
            $table->uuid('log_id')->primary();
            $table->uuid('sender_id')->constrained('members', 'member_id');
            $table->uuid('recipient_id')->constrained('members', 'member_id');
            $table->decimal('amount', 10, 2);
            $table->string('status'); // LOCKED, CONFIRMED, CANCELLED
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_log');
    }
};
