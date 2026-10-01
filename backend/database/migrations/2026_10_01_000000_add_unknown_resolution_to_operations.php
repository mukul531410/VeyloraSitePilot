<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->string('resolution')->nullable()->after('status');
            $table->timestamp('resolved_at')->nullable()->after('resolution');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->text('resolution_reason')->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropForeign(['resolved_by']);
            $table->dropColumn(['resolution', 'resolved_at', 'resolved_by', 'resolution_reason']);
        });
    }
};
