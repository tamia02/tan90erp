<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('tan90_role_id')->nullable()->constrained('tan90_roles')->nullOnDelete();
            $table->string('employee_id')->unique();
            $table->string('department')->nullable();
            // Nullable, no DB-level FK: tan90_plants/tan90_locations are created in later
            // migrations, and a plant user may be scoped to either or both.
            $table->unsignedBigInteger('assigned_plant_id')->nullable();
            $table->unsignedBigInteger('assigned_location_id')->nullable();
            $table->boolean('all_locations')->default(false);
            $table->enum('mfa_status', ['enabled', 'pending', 'disabled'])->default('pending');
            $table->enum('status', ['active', 'inactive', 'locked'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('assigned_plant_id');
            $table->index('assigned_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_user_profiles');
    }
};
