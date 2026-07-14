<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_data_quality_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_data_quality_rule_id')->nullable()->constrained('tan90_data_quality_rules')->nullOnDelete();
            $table->string('rule_code');
            $table->string('entity');
            $table->string('record_label');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('issue');
            $table->enum('severity', ['critical', 'high', 'medium', 'low'])->default('medium');
            $table->string('owner')->nullable();
            $table->timestamp('detected_at')->useCurrent();
            $table->string('suggested_action')->nullable();
            $table->enum('resolution_status', ['open', 'review', 'resolved'])->default('open');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->unique(['rule_code', 'record_label'], 'tan90_dq_issue_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_data_quality_issues');
    }
};
