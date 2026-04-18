<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Old grid-era defaults for these keys (5% / 10x / 3) don't fit the new
        // short-scalp strategy (10% / 25x / 10). Deleting the rows lets the
        // new config defaults from config/crypto.php take over.
        DB::table('bot_settings')
            ->whereIn('key', ['leverage', 'position_size_pct', 'max_positions'])
            ->delete();
    }

    public function down(): void
    {
        // No-op — we don't restore the old grid-era values.
    }
};
