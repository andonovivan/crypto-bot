<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->decimal('wallet_balance', 20, 8);
            $table->decimal('available_balance', 20, 8);
            $table->decimal('unrealized_profit', 20, 8)->default(0);
            $table->decimal('margin_balance', 20, 8)->default(0);
            $table->decimal('position_margin', 20, 8)->default(0);
            $table->decimal('maint_margin', 20, 8)->default(0);
            $table->unsignedSmallInteger('open_positions')->default(0);
            $table->boolean('is_dry_run')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['is_dry_run', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_snapshots');
    }
};
