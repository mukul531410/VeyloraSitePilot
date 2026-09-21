<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_heartbeats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_connection_id');
            $table->string('connector_version');
            $table->string('wordpress_version')->nullable();
            $table->string('php_version')->nullable();
            $table->timestamp('reported_at');
            $table->string('status');
            $table->timestamps();

            $table->foreign('site_connection_id')
                ->references('id')
                ->on('site_connections')
                ->cascadeOnDelete();

            $table->index(['site_connection_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_heartbeats');
    }
};
