<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->string('trigger_type')->default('schedule');
            $table->json('schedule_json');
            $table->json('conditions_json')->nullable();
            $table->string('action_type');
            $table->json('target_json');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->cascadeOnDelete();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->index(['organization_id', 'enabled']);
            $table->index(['site_id', 'enabled']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('automation_rule_id');
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->ulid('operation_id')->nullable();
            $table->string('occurrence_key');
            $table->string('status')->default('pending');
            $table->json('evaluation_metadata_json')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('automation_rule_id')
                ->references('id')
                ->on('automation_rules')
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->cascadeOnDelete();

            $table->foreign('operation_id')
                ->references('id')
                ->on('operations')
                ->nullOnDelete();

            $table->unique(['automation_rule_id', 'occurrence_key']);
            $table->index(['organization_id', 'status']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
