<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_metrics', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->string('metric_type');
            $table->decimal('value', 15, 4)->nullable();
            $table->string('unit')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->cascadeOnDelete();

            $table->index(['site_id', 'metric_type']);
            $table->index(['site_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_metrics');
    }
};
