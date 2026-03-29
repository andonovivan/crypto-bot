<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pump_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('side')->default('SHORT');
            $table->decimal('entry_price', 20, 8);
            $table->decimal('quantity', 20, 8);
            $table->decimal('position_size_usdt', 20, 4);
            $table->decimal('stop_loss_price', 20, 8)->nullable();
            $table->decimal('take_profit_price', 20, 8)->nullable();
            $table->decimal('current_price', 20, 8)->nullable();
            $table->decimal('unrealized_pnl', 20, 4)->nullable();
            $table->integer('leverage')->default(5);
            $table->string('status')->default('open');
            $table->string('exchange_order_id')->nullable();
            $table->boolean('is_dry_run')->default(true);
            $table->timestamp('opened_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
