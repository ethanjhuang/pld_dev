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
        Schema::create('membership_cards', function (Blueprint $table) {
            $table->uuid('card_id')->primary();
            $table->uuid('member_id')->constrained('members', 'member_id')->cascadeOnDelete();
            $table->decimal('remaining_points', 10, 2)->default(0);
            $table->decimal('locked_points', 10, 2)->default(0); // 後補鎖定點數
            $table->string('type')->default('POINTS');
            $table->decimal('purchase_amount', 10, 2);
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            // 確保一個用戶只有一張主要的點數卡
            $table->unique(['member_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_card');
    }
};
