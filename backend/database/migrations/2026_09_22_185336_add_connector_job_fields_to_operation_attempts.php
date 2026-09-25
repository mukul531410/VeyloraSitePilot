<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_attempts', function (Blueprint $table) {
            $table->ulid('connector_job_id')->unique()->nullable()->after('attempt_number')->change();
            $table->string('lock_token')->nullable()->after('connector_job_id');
            $table->boolean('retryable')->default(false)->after('lock_token');
            $table->timestamp('timeout_at')->nullable()->after('retryable');
        });
    }

    public function down(): void
    {
        Schema::table('operation_attempts', function (Blueprint $table) {
            $table->dropUnique(['connector_job_id']);
            $table->dropColumn(['lock_token', 'retryable', 'timeout_at']);
        });
    }
};
