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
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('menus')->nullOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('path')->nullable();
            $table->string('component')->nullable();
            $table->string('icon')->nullable();
            $table->string('type', 20)->default('page')->index();
            $table->string('permission_name')->nullable()->index();
            $table->foreignId('permission_id')->nullable()->constrained('permissions')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0)->index();
            $table->boolean('is_visible')->default(true)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index('permission_id');
            $table->index(['is_active', 'is_visible', 'sort', 'id'], 'menus_active_visible_sort_id_idx');
            $table->index(['parent_id', 'sort', 'id'], 'menus_parent_sort_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
