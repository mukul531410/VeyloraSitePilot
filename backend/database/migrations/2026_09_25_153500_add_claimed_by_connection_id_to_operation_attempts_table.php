<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_attempts', function (Blueprint $table) {
            $table->ulid('claimed_by_connection_id')->nullable()->after('connector_job_id');
            $table->foreign('claimed_by_connection_id')
                ->references('id')
                ->on('site_connections')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operation_attempts', function (Blueprint $table) {
            $table->dropForeign(['claimed_by_connection_id']);
            $table->dropColumn('claimed_by_connection_id');
        });
    }
};
