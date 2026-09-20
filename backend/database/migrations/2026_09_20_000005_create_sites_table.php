<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('name');
            $table->string('url');
            $table->string('environment')->default('production');
            $table->string('status')->default('active');
            $table->string('business_criticality')->nullable();
            $table->string('timezone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
