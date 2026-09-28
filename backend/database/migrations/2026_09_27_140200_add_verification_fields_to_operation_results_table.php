<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_results', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('verification_status');
            $table->text('verification_error')->nullable()->after('verified_at');
        });

        Schema::table('operation_results', function (Blueprint $table) {
            $table->index(
                ['operation_attempt_id', 'verification_status'],
                'operation_results_attempt_verification_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('operation_results', function (Blueprint $table) {
            $table->dropIndex('operation_results_attempt_verification_status_index');
        });

        Schema::table('operation_results', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'verification_error']);
        });
    }
};