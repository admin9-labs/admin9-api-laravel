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
        Schema::create('dictionary_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dictionary_type_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 100);
            $table->string('value')->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('sort')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['dictionary_type_id', 'code']);
            $table->index(['dictionary_type_id', 'is_active', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dictionary_items');
    }
};
