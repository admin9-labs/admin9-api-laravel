<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('menus')
            ->where('seed_key', 'admin9.core.system.roles')
            ->where('icon', 'team')
            ->update(['icon' => 'user-group']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
