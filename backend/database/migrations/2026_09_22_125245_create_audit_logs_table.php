<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->ulid('site_id')->nullable();
            $table->string('action');
            $table->string('target_type');
            $table->ulid('target_id');
            $table->ulid('correlation_id');
            $table->string('policy_result')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->nullOnDelete();

            $table->index(['organization_id', 'action']);
            $table->index(['organization_id', 'target_type', 'target_id']);
            $table->index(['correlation_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};