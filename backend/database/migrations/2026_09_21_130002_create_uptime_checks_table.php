<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->string('status');
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->cascadeOnDelete();

            $table->index(['site_id', 'status']);
            $table->index(['site_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_checks');
    }
};
