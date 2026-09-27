<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_master_change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no')->unique();
            // entity_type is the EntityRegistry slug (e.g. "legal-entities"), entity_id is the
            // row id in that entity's own table. No DB-level polymorphic FK by design: the
            // target table varies per entity_type.
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('record_code')->nullable();
            $table->json('proposed_changes');
            $table->json('previous_values')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('owner')->nullable();
            $table->enum('priority', ['Low', 'Medium', 'High', 'Critical'])->default('Medium');
            $table->enum('approval_status', ['draft', 'pending', 'review', 'approved', 'rejected'])->default('draft');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_master_change_requests');
    }
};
