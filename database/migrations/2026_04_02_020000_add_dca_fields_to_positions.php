<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->unsignedTinyInteger('layer_count')->default(1)->after('leverage');
            $table->decimal('atr_value', 20, 8)->nullable()->after('layer_count');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['layer_count', 'atr_value']);
        });
    }
};
