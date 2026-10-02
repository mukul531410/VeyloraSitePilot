<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_run_recoveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('automation_run_id');
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action');
            $table->string('request_idempotency_key');
            $table->text('reason')->nullable();
            $table->string('state');
            $table->ulid('active_automation_run_id')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('result_metadata_json')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('automation_run_id')->references('id')->on('automation_runs')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();

            // Recovery state is separate from automation_runs.status, so the run stays
            // `evaluating` while a recovery is requested, checked or blocked. Only an
            // occupying attempt claims the run; blocked attempts release it so a later
            // legitimate attempt is still possible.
            $table->unique('active_automation_run_id', 'automation_run_recoveries_active_run_unique');
            $table->index(['organization_id', 'site_id']);
            $table->index(['automation_run_id', 'state']);
            $table->index(['request_idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_run_recoveries');
    }
};
