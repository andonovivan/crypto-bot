<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('funding_fee', 20, 8)->default(0)->after('total_entry_fee');
            $table->timestamp('last_funding_at')->nullable()->after('funding_fee');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['funding_fee', 'last_funding_at']);
        });
    }
};
