<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->string('check_type');
            $table->string('status');
            $table->json('value_json')->nullable();
            $table->timestamp('checked_at');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->cascadeOnDelete();

            $table->index(['site_id', 'check_type']);
            $table->index(['site_id', 'checked_at']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
