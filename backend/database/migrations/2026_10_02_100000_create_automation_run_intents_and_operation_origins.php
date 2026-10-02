<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_run_intents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('automation_run_id')->unique();
            $table->unsignedSmallInteger('intent_version');
            $table->timestamp('captured_at');
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->ulid('automation_rule_id');
            $table->string('occurrence_key');
            $table->string('original_operation_type');
            $table->json('original_target_json');
            $table->unsignedBigInteger('original_requester_id');
            $table->string('original_idempotency_key');
            $table->json('policy_context_snapshot');
            $table->timestamps();

            $table->foreign('automation_run_id')->references('id')->on('automation_runs')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('automation_rule_id')->references('id')->on('automation_rules')->restrictOnDelete();
            $table->foreign('original_requester_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['organization_id', 'site_id']);
            $table->index(['automation_rule_id', 'occurrence_key']);
        });

        Schema::create('automation_operation_origins', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('automation_run_id')->unique();
            $table->ulid('operation_id')->unique();
            $table->ulid('site_id');
            $table->ulid('organization_id');
            $table->timestamp('linked_at');
            $table->unsignedSmallInteger('version');
            $table->timestamps();

            $table->foreign('automation_run_id')->references('id')->on('automation_runs')->restrictOnDelete();
            $table->foreign('operation_id')->references('id')->on('operations')->restrictOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['organization_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_operation_origins');
        Schema::dropIfExists('automation_run_intents');
    }
};
