<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('operation_id');
            $table->unsignedInteger('attempt_number');
            $table->string('status');
            $table->ulid('connector_job_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code')->nullable();
            $table->json('error_details_json')->nullable();
            $table->timestamps();

            $table->foreign('operation_id')
                ->references('id')
                ->on('operations')
                ->cascadeOnDelete();

            $table->index(['operation_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_attempts');
    }
};