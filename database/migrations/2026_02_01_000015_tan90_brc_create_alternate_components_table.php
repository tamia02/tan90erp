<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_alternate_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_component_id')->constrained('tan90_components')->cascadeOnDelete();
            $table->foreignId('tan90_alternate_component_id')->constrained('tan90_components')->restrictOnDelete();
            $table->decimal('ratio', 10, 4)->default(1);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->string('approval_status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_alternate_components');
    }
};
