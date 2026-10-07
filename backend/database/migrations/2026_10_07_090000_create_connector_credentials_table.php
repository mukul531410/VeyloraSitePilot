<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_credentials', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->ulid('id')->primary();
            $table->ulid('site_connection_id');
            $table->text('secret_ciphertext');
            $table->unsignedInteger('version');
            // primary, overlap, and revoked are the only lifecycle states.
            $table->string('status', 16);
            $table->timestamp('issued_at');
            $table->timestamp('overlap_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('site_connection_id')->references('id')->on('site_connections')->cascadeOnDelete();
            $table->unique(['site_connection_id', 'version'], 'connector_credentials_connection_version_unique');
            $table->index(['site_connection_id', 'status'], 'connector_credentials_connection_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_credentials');
    }
};
