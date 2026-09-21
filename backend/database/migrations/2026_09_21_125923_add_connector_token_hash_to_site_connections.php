<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_connections', function (Blueprint $table) {
            $table->string('connector_token_hash')->nullable()->after('credential_ciphertext');
            $table->index('connector_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('site_connections', function (Blueprint $table) {
            $table->dropIndex(['connector_token_hash']);
            $table->dropColumn('connector_token_hash');
        });
    }
};
