<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scanned_coins', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique();
            $table->decimal('price', 20, 8)->nullable();
            $table->decimal('price_change_pct_24h', 10, 4)->nullable();
            $table->decimal('volume_24h', 20, 2)->nullable();
            $table->decimal('avg_volume_7d', 20, 2)->nullable();
            $table->decimal('volume_multiplier', 10, 4)->nullable();
            $table->decimal('high_24h', 20, 8)->nullable();
            $table->decimal('low_24h', 20, 8)->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scanned_coins');
    }
};
