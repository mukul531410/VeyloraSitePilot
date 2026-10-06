<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->ulid('site_id')->nullable();
            $table->string('source_type', 64);
            $table->string('source_id', 64);
            $table->string('type', 64);
            $table->string('severity', 32);
            $table->string('title');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->unique(['source_type', 'source_id', 'type', 'user_id'], 'notification_source_recipient_unique');
            $table->index(['user_id', 'read_at', 'created_at'], 'notifications_user_read_created_index');
            $table->index(['organization_id', 'created_at'], 'notifications_org_created_index');
            $table->index(['site_id', 'created_at'], 'notifications_site_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
