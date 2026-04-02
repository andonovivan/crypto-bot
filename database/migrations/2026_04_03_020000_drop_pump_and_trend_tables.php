<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropForeign(['trend_signal_id']);
            $table->dropColumn('trend_signal_id');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropForeign(['pump_signal_id']);
            $table->dropColumn('pump_signal_id');
        });

        Schema::dropIfExists('trend_signals');
        Schema::dropIfExists('pump_signals');
    }

    public function down(): void
    {
        Schema::create('pump_signals', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('status');
            $table->float('price_change_pct')->nullable();
            $table->float('volume_multiplier')->nullable();
            $table->float('entry_price')->nullable();
            $table->float('peak_price')->nullable();
            $table->float('current_price')->nullable();
            $table->timestamps();
        });

        Schema::create('trend_signals', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('status');
            $table->string('direction')->nullable();
            $table->integer('score')->default(0);
            $table->float('entry_price')->nullable();
            $table->float('atr_value')->nullable();
            $table->json('indicators')->nullable();
            $table->timestamps();
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('pump_signal_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('trend_signal_id')->nullable()->after('pump_signal_id')->constrained()->nullOnDelete();
        });
    }
};
