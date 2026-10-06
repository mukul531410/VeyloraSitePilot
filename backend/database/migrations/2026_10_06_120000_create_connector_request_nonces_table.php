<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_request_nonces', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            // TEMPORARY Commit 1 scope. Commit 2 must replace this FK and unique
            // scope with connector_credentials.id before credential rotation ships.
            $table->ulid('site_connection_id');
            $table->char('nonce_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            $table->foreign('site_connection_id')
                ->references('id')->on('site_connections')->cascadeOnDelete();
            $table->unique(['site_connection_id', 'nonce_hash'], 'connector_nonce_scope_unique');
            $table->index('expires_at', 'connector_nonce_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_request_nonces');
    }
};
