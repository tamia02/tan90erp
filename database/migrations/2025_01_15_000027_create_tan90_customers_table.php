<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_customers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('gstin')->nullable();
            $table->enum('segment', ['Pharma', 'Frozen Foods', 'Dairy', 'Agriculture', 'E-commerce', 'Other'])->default('Other');
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('credit_limit')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('sales_owner')->nullable();
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
        Schema::dropIfExists('tan90_customers');
    }
};
