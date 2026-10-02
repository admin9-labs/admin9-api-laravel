<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_directories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('file_directories')->restrictOnDelete();
            $table->string('scope_key', 32);
            $table->string('name', 64);
            $table->timestamps();
            $table->unique(['scope_key', 'name'], 'file_directories_scope_name_unique');
            $table->index(['parent_id', 'id']);
        });

        Schema::table('files', function (Blueprint $table): void {
            $table->foreignId('directory_id')->nullable()->constrained('file_directories')->restrictOnDelete();
            $table->index(['directory_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropIndex(['directory_id', 'id']);
            $table->dropConstrainedForeignId('directory_id');
        });
        Schema::dropIfExists('file_directories');
    }
};
