<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('trailing_tp_armed')->default(false)->after('partial_tp_taken');
            $table->decimal('trailing_extreme_price', 25, 12)->nullable()->after('trailing_tp_armed');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['trailing_tp_armed', 'trailing_extreme_price']);
        });
    }
};
