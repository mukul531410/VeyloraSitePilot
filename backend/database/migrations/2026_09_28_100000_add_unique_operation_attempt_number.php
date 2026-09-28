<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'operation_attempts_operation_id_attempt_number_unique';

    public function up(): void
    {
        $hasDuplicateAttemptNumbers = DB::table('operation_attempts')
            ->select('operation_id', 'attempt_number')
            ->groupBy('operation_id', 'attempt_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateAttemptNumbers) {
            throw new \RuntimeException(
                'Cannot add unique operation attempt numbering while duplicate (operation_id, attempt_number) rows exist.'
            );
        }

        Schema::table('operation_attempts', function (Blueprint $table): void {
            $table->unique(['operation_id', 'attempt_number'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('operation_attempts', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX_NAME);
        });
    }
};
