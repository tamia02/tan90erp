<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_location_gst_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('tan90_location_id')->constrained('tan90_locations')->cascadeOnDelete();
            $table->string('gstin');
            $table->string('legal_name')->nullable();
            $table->string('registration_type')->nullable();
            $table->date('effective_from')->nullable();
            $table->enum('gst_status', ['pending', 'verified', 'failed'])->default('pending');
            $table->timestamp('verified_at')->nullable();
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
        Schema::dropIfExists('tan90_location_gst_registrations');
    }
};
