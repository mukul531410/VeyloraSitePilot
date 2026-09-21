<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->string('status')->default('pending');
            $table->string('connector_version')->nullable();
            $table->text('credential_ciphertext')->nullable();
            $table->unsignedInteger('credential_version')->default(1);
            $table->string('connection_intent')->unique()->nullable();
            $table->timestamp('intent_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->cascadeOnDelete();

            $table->index(['site_id', 'status']);
            $table->index(['site_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_connections');
    }
};
