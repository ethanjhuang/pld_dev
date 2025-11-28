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
        Schema::table('membership_cards', function (Blueprint $table) {
        $table->string('card_status')->default('ACTIVE')->after('purchase_amount');
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_cards', function (Blueprint $table) {
            //
        });
    }
};
