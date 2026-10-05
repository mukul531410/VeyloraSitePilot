<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['site_core_states', 'site_plugins', 'site_themes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                // Null means an existing/legacy observation did not preserve whether
                // the connector explicitly sent update_available.
                $table->boolean('update_available_reported')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['site_core_states', 'site_plugins', 'site_themes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('update_available_reported');
            });
        }
    }
};
