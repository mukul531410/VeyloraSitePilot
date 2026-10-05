<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('available_updates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->string('type', 16);
            $table->string('item_identifier', 191);
            $table->string('severity', 16)->default('info');
            $table->string('status', 16)->default('open');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'type', 'item_identifier'], 'available_updates_identity_unique');
            $table->index(['site_id', 'status', 'last_seen_at'], 'available_updates_site_status_seen_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('available_updates');
    }
};
