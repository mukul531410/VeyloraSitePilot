<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A snapshot is the immutable record of one connector inventory sync. Rows
        // in the component tables always belong to exactly one snapshot so history
        // is never overwritten in place.
        Schema::create('inventory_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->string('snapshot_type')->default('full');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status')->default('pending');
            $table->string('checksum', 64)->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();

            $table->index(['site_id', 'completed_at']);
        });

        Schema::create('site_plugins', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->ulid('inventory_snapshot_id');
            $table->string('plugin_key');
            $table->string('name');
            $table->string('version')->nullable();
            $table->boolean('update_available')->default(false);
            $table->boolean('active')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('inventory_snapshot_id')->references('id')->on('inventory_snapshots')->cascadeOnDelete();

            // One snapshot cannot report the same plugin twice.
            $table->unique(['inventory_snapshot_id', 'plugin_key'], 'site_plugins_snapshot_key_unique');
            $table->index(['site_id', 'update_available']);
        });

        Schema::create('site_themes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->ulid('inventory_snapshot_id');
            $table->string('theme_key');
            $table->string('name');
            $table->string('version')->nullable();
            $table->boolean('update_available')->default(false);
            $table->boolean('active')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('inventory_snapshot_id')->references('id')->on('inventory_snapshots')->cascadeOnDelete();

            $table->unique(['inventory_snapshot_id', 'theme_key'], 'site_themes_snapshot_key_unique');
            $table->index(['site_id', 'update_available']);
        });

        Schema::create('site_core_states', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->ulid('inventory_snapshot_id');
            $table->string('wordpress_version')->nullable();
            $table->string('php_version')->nullable();
            $table->boolean('update_available')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('inventory_snapshot_id')->references('id')->on('inventory_snapshots')->cascadeOnDelete();

            // A snapshot reports at most one core state for the site.
            $table->unique('inventory_snapshot_id', 'site_core_states_snapshot_unique');
            $table->index(['site_id', 'update_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_core_states');
        Schema::dropIfExists('site_themes');
        Schema::dropIfExists('site_plugins');
        Schema::dropIfExists('inventory_snapshots');
    }
};
