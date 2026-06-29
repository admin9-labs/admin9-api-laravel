<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('permissions', static function (Blueprint $table) {
            $table->string('display_name')->nullable();
            $table->string('group')->nullable()->index();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0)->index();
            $table->boolean('is_system')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();

            $table->index(['guard_name', 'group', 'sort', 'name'], 'permissions_guard_group_sort_name_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permissions', static function (Blueprint $table) {
            $table->dropIndex('permissions_guard_group_sort_name_idx');
            $table->dropIndex(['group']);
            $table->dropIndex(['sort']);
            $table->dropIndex(['is_system']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'display_name',
                'group',
                'description',
                'sort',
                'is_system',
                'is_active',
            ]);
        });
    }
};
