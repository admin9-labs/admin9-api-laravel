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
        Schema::connection($this->getConnection())->table('menus', function (Blueprint $table): void {
            $table->dropForeign(['permission_id']);
            $table->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $table->dropIndex(['permission_name']);
            $table->dropColumn('permission_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->table('menus', function (Blueprint $table): void {
            $table->dropForeign(['permission_id']);
            $table->string('permission_name')->nullable()->index();
            $table->foreign('permission_id')->references('id')->on('permissions')->nullOnDelete();
        });
    }
};
