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
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table): void {
                $table->index('subject_id', 'activity_log_subject_id_index');
                $table->index('causer_id', 'activity_log_causer_id_index');
            });

        Schema::table('login_logs', function (Blueprint $table): void {
            $table->index('subject_id', 'login_logs_subject_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('login_logs', function (Blueprint $table): void {
            $table->dropIndex('login_logs_subject_id_index');
        });

        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table): void {
                $table->dropIndex('activity_log_subject_id_index');
                $table->dropIndex('activity_log_causer_id_index');
            });
    }
};
