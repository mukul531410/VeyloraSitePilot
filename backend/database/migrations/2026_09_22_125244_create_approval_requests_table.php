<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->ulid('operation_id');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

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
                ->cascadeOnDelete();

            $table->foreign('requested_by')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('reviewed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};