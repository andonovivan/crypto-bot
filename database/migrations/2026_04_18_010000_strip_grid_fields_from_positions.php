<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['layer_count', 'atr_value', 'best_price', 'breakeven_activated']);
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->unsignedTinyInteger('layer_count')->default(1);
            $table->decimal('atr_value', 20, 8)->nullable();
            $table->decimal('best_price', 20, 8)->nullable();
            $table->boolean('breakeven_activated')->default(false);
        });
    }
};
