<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('operation_id');
            $table->string('result_status');
            $table->json('expected_state_json')->nullable();
            $table->json('actual_state_json')->nullable();
            $table->string('verification_status')->nullable();
            $table->text('result_summary')->nullable();
            $table->timestamps();

            $table->foreign('operation_id')
                ->references('id')
                ->on('operations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_results');
    }
};