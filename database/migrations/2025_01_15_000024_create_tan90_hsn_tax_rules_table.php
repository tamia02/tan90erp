<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_hsn_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('hsn')->unique();
            $table->string('description');
            $table->enum('gst_rate', ['0%', '5%', '12%', '18%', '28%'])->default('18%');
            $table->string('cess')->nullable();
            $table->enum('input_credit', ['Allowed', 'Blocked', 'Conditional'])->default('Allowed');
            $table->date('effective_from')->nullable();
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
        Schema::dropIfExists('tan90_hsn_tax_rules');
    }
};
