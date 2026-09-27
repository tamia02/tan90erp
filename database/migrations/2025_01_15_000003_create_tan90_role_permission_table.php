<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_role_id')->constrained('tan90_roles')->cascadeOnDelete();
            $table->foreignId('tan90_permission_id')->constrained('tan90_permissions')->cascadeOnDelete();
            $table->boolean('allowed')->default(false);
            $table->timestamps();
            $table->unique(['tan90_role_id', 'tan90_permission_id'], 'tan90_role_permission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_role_permission');
    }
};
