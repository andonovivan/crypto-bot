<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->string('symbol');
            $table->string('side');
            $table->string('type')->default('close');
            $table->decimal('entry_price', 20, 8);
            $table->decimal('exit_price', 20, 8);
            $table->decimal('quantity', 20, 8);
            $table->decimal('pnl', 20, 4);
            $table->decimal('pnl_pct', 10, 4);
            $table->decimal('fees', 20, 4)->default(0);
            $table->string('close_reason');
            $table->string('exchange_order_id')->nullable();
            $table->boolean('is_dry_run')->default(true);
            $table->timestamps();

            $table->index('symbol');
            $table->index('close_reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
