<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One person doing several jobs (e.g. Store Manager + Store Executive + QC).
// users.role stays the person's single *main* role - it still decides their
// home page and every role-specific display rule - and each row here adds
// the screens of one more GRN role on top of it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_extra_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_extra_roles');
    }
};
