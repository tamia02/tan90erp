<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('gstin')->nullable();
            $table->enum('gst_status', ['pending', 'verified', 'failed'])->default('pending');
            $table->string('category')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('contact')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->enum('payment_terms', ['Advance', '15 Days', '30 Days', '45 Days', '60 Days'])->nullable();
            $table->decimal('rating', 3, 1)->nullable();
            $table->enum('portal_enabled', ['Yes', 'No'])->default('No');
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
        Schema::dropIfExists('tan90_vendors');
    }
};
