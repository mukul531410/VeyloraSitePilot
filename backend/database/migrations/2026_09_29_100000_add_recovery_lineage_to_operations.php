<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->foreignUlid('recovery_of_operation_id')
                ->nullable()
                ->constrained('operations')
                ->restrictOnDelete();
            $table->unique('recovery_of_operation_id', 'operations_recovery_of_operation_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropForeign(['recovery_of_operation_id']);
            $table->dropUnique('operations_recovery_of_operation_id_unique');
            $table->dropColumn('recovery_of_operation_id');
        });
    }
};
