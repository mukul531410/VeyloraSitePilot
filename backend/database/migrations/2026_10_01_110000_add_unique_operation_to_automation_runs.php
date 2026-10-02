<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->unique('operation_id', 'automation_runs_operation_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->dropUnique('automation_runs_operation_id_unique');
        });
    }
};
