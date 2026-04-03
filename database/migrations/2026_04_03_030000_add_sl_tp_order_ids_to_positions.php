<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('sl_order_id')->nullable()->after('exchange_order_id');
            $table->string('tp_order_id')->nullable()->after('sl_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['sl_order_id', 'tp_order_id']);
        });
    }
};
