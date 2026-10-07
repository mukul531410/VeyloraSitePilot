<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_capabilities', function (Blueprint $table): void {
            $table->boolean('reported_supported')->nullable()->after('enabled');
            $table->timestamp('reported_at')->nullable()->after('reported_supported');
        });
    }

    public function down(): void
    {
        Schema::table('connector_capabilities', function (Blueprint $table): void {
            $table->dropColumn(['reported_supported', 'reported_at']);
        });
    }
};
