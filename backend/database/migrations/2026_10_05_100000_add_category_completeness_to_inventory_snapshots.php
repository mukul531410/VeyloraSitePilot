<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_snapshots', function (Blueprint $table): void {
            // Null preserves unknown completeness for snapshots from legacy clients.
            $table->boolean('wordpress_complete')->nullable();
            $table->boolean('plugins_complete')->nullable();
            $table->boolean('themes_complete')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['wordpress_complete', 'plugins_complete', 'themes_complete']);
        });
    }
};
