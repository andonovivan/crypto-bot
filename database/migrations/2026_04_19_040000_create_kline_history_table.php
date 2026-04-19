<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kline_history', function (Blueprint $table) {
            $table->string('symbol', 30);
            $table->string('interval', 5);
            $table->bigInteger('open_time');
            $table->decimal('open', 25, 12);
            $table->decimal('high', 25, 12);
            $table->decimal('low', 25, 12);
            $table->decimal('close', 25, 12);
            $table->decimal('volume', 30, 12);
            $table->bigInteger('close_time');
            $table->decimal('quote_volume', 30, 12);
            $table->integer('trade_count')->default(0);

            $table->primary(['symbol', 'interval', 'open_time']);
            $table->index(['interval', 'open_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kline_history');
    }
};
