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
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_user_id');
            $table->string('wallet_address');
            $table->decimal('amount', 20, 8);
            $table->decimal('network_fee', 20, 8)->default(1);
            $table->decimal('deposit_fee_percent', 5, 2)->default(4);
            $table->decimal('total_deducted', 20, 8);
            $table->string('network')->default('TRC20');
            $table->string('currency')->default('USDT');
            $table->string('status')->default('pending');
            $table->text('tx_hash')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
