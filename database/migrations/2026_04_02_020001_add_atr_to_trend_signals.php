<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trend_signals', function (Blueprint $table) {
            $table->decimal('atr_value', 20, 8)->nullable()->after('volume_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('trend_signals', function (Blueprint $table) {
            $table->dropColumn('atr_value');
        });
    }
};
