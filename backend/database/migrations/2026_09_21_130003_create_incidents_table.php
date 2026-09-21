<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->string('type');
            $table->string('severity');
            $table->string('status')->default('detected');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->cascadeOnDelete();

            $table->index(['site_id', 'status']);
            $table->index(['site_id', 'type']);
            $table->index(['site_id', 'severity']);
            $table->index(['site_id', 'first_detected_at']);
            $table->index(['site_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
