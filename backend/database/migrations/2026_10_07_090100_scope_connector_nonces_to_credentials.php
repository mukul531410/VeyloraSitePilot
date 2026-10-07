<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Commit 1's resolver always failed closed, so no production request
        // could have reserved a nonce for a valid HMAC credential. Legacy rows
        // have no credential identity to map safely; discard them before the
        // nonce scope changes rather than assigning them to the wrong credential.
        DB::table('connector_request_nonces')->delete();

        Schema::table('connector_request_nonces', function (Blueprint $table): void {
            $table->dropForeign(['site_connection_id']);
            $table->dropUnique('connector_nonce_scope_unique');
            $table->dropColumn('site_connection_id');
        });

        Schema::table('connector_request_nonces', function (Blueprint $table): void {
            $table->ulid('credential_id')->after('id');
            $table->foreign('credential_id')->references('id')->on('connector_credentials')->cascadeOnDelete();
            $table->unique(['credential_id', 'nonce_hash'], 'connector_nonce_scope_unique');
        });
    }

    public function down(): void
    {
        DB::table('connector_request_nonces')->delete();
        Schema::table('connector_request_nonces', function (Blueprint $table): void {
            $table->dropForeign(['credential_id']);
            $table->dropUnique('connector_nonce_scope_unique');
            $table->dropColumn('credential_id');
        });
        Schema::table('connector_request_nonces', function (Blueprint $table): void {
            $table->ulid('site_connection_id')->after('id');
            $table->foreign('site_connection_id')->references('id')->on('site_connections')->cascadeOnDelete();
            $table->unique(['site_connection_id', 'nonce_hash'], 'connector_nonce_scope_unique');
        });
    }
};
