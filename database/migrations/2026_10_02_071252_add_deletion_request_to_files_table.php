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
        Schema::table('files', function (Blueprint $table): void {
            $table->unsignedBigInteger('deletion_requested_by')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->index(['deletion_started_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropIndex(['deletion_started_at', 'id']);
            $table->dropColumn(['deletion_requested_by', 'deletion_requested_at']);
        });
    }
};
