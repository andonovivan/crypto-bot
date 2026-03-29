<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pump_signals', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->decimal('pump_price', 20, 8);
            $table->decimal('peak_price', 20, 8);
            $table->decimal('current_price', 20, 8);
            $table->decimal('price_change_pct', 10, 4);
            $table->decimal('volume_multiplier', 10, 4);
            $table->decimal('drop_from_peak_pct', 10, 4)->default(0);
            $table->string('status')->default('detected');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pump_signals');
    }
};
