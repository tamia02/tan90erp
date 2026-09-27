<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_cost_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('rate_type', ['material', 'labor', 'machine', 'utility', 'overhead'])->default('material');
            $table->string('rate_name');
            // Points at a component or work center depending on rate_type; kept as a
            // plain nullable id (no FK) since it targets two different tables.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('rate', 14, 4);
            $table->string('uom')->nullable();
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
        Schema::dropIfExists('tan90_cost_rates');
    }
};
