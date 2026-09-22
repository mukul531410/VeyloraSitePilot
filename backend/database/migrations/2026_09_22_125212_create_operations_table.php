<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('task_id')->nullable();
            $table->ulid('site_id');
            $table->string('operation_type');
            $table->json('target_json')->nullable();
            $table->string('status')->default('requested');
            $table->string('policy_result')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->string('idempotency_key');
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->cascadeOnDelete();

            $table->foreign('requested_by')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->index(['site_id', 'status']);
            $table->index(['site_id', 'operation_type']);
            $table->unique(['site_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};