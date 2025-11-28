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
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('member_id')->primary();
            $table->unsignedBigInteger('wp_user_id')->nullable(); // 連結 WP
            $table->string('line_id')->unique();
            $table->string('referral_code', 10)->unique();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->unique();
            $table->enum('role', ['PARENT', 'ADMIN', 'COACH'])->default('PARENT');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member');
    }
};
