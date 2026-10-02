<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_token_refreshes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('member_auth_session_id')->constrained('member_auth_sessions')->cascadeOnDelete();
            $table->string('source_jti_hash', 64)->unique();
            $table->string('successor_jti_hash', 64);
            $table->unsignedInteger('auth_version');
            $table->text('token');
            $table->timestamp('expires_at')->index();
            $table->timestamp('blacklisted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_token_refreshes');
    }
};
