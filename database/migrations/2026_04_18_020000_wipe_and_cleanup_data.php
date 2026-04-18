<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::table('trades')->truncate();
        DB::table('positions')->truncate();

        DB::table('bot_settings')
            ->where('key', 'LIKE', 'grid_%')
            ->orWhereIn('key', ['watchlist', 'max_position_usdt'])
            ->delete();

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
    }
};
