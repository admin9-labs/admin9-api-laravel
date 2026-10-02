<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('auth_version');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_auth_sessions');
    }
};
