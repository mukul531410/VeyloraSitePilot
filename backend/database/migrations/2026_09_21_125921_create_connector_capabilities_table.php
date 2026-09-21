<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_capabilities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_connection_id');
            $table->string('capability_key');
            $table->boolean('enabled')->default(true);
            $table->timestamp('discovered_at');
            $table->timestamp('updated_at')->nullable();

            $table->foreign('site_connection_id')
                ->references('id')
                ->on('site_connections')
                ->cascadeOnDelete();

            $table->unique(['site_connection_id', 'capability_key']);
            $table->index(['site_connection_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_capabilities');
    }
};
