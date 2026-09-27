<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_master_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->string('module');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('record_label')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role_label')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('summary')->nullable();
            $table->json('changed_fields')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_master_audit_logs');
    }
};
