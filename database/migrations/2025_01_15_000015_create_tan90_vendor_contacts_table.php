<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_vendor_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_vendor_id')->constrained('tan90_vendors')->cascadeOnDelete();
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->enum('is_primary', ['Yes', 'No'])->default('No');
            $table->string('notification_role')->nullable();
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
        Schema::dropIfExists('tan90_vendor_contacts');
    }
};
