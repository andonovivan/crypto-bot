<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_signals', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('direction'); // LONG or SHORT
            $table->unsignedTinyInteger('score'); // 0-100
            $table->decimal('entry_price', 20, 8)->nullable();
            $table->decimal('current_price', 20, 8)->nullable();
            $table->boolean('ema_cross')->default(false);
            $table->decimal('rsi_value', 8, 4)->nullable();
            $table->decimal('macd_histogram', 20, 8)->nullable();
            $table->decimal('volume_ratio', 10, 4)->nullable();
            $table->string('status')->default('detected');
            $table->text('notes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'status']);
            $table->index('created_at');
        });

        // Add trend_signal_id to positions table
        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('trend_signal_id')->nullable()->after('pump_signal_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropForeign(['trend_signal_id']);
            $table->dropColumn('trend_signal_id');
        });
        Schema::dropIfExists('trend_signals');
    }
};
