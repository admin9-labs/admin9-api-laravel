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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('type', 16)->index();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('mime_type', 128);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 16)->default('ready');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('deletion_token')->nullable();
            $table->timestamp('deletion_started_at')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path']);
            $table->index(['type', 'id']);
            $table->index(['deletion_token', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
