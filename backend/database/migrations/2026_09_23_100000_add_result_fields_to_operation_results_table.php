<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_results', function (Blueprint $table) {
            $table->ulid('operation_attempt_id')->unique()->nullable()->after('operation_id');
            $table->string('connector_job_id')->unique()->nullable()->after('operation_attempt_id');
            $table->timestamp('cache_cleared_at')->nullable()->after('connector_job_id');
            $table->json('cleared_types')->nullable()->after('cache_cleared_at');
            $table->string('cache_generation')->nullable()->after('cleared_types');
            $table->string('error_code')->nullable()->after('cache_generation');
            $table->text('error_message')->nullable()->after('error_code');

            $table->foreign('operation_attempt_id')
                ->references('id')
                ->on('operation_attempts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operation_results', function (Blueprint $table) {
            $table->dropForeign(['operation_attempt_id']);
            $table->dropColumn([
                'operation_attempt_id',
                'connector_job_id',
                'cache_cleared_at',
                'cleared_types',
                'cache_generation',
                'error_code',
                'error_message',
            ]);
        });
    }
};
