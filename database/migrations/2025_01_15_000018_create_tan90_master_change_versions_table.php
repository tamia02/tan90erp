<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_master_change_versions', function (Blueprint $table) {
            $table->id();
            // Named explicitly - the auto-generated FK name
            // ("tan90_master_change_versions_tan90_master_change_request_id_foreign")
            // exceeds MySQL's 64-character identifier limit.
            $table->foreignId('tan90_master_change_request_id')->nullable();
            $table->foreign('tan90_master_change_request_id', 'tan90_mcv_request_fk')
                ->references('id')->on('tan90_master_change_requests')->nullOnDelete();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->unique(['entity_type', 'entity_id', 'version_number'], 'tan90_change_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_master_change_versions');
    }
};
