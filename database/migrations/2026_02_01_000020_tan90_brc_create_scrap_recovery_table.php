<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_scrap_recovery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_bom_version_id')->constrained('tan90_bom_versions')->cascadeOnDelete();
            $table->string('scrap_type');
            $table->decimal('quantity', 14, 4);
            $table->string('uom')->nullable();
            $table->decimal('recovery_percent', 5, 2)->default(0);
            $table->string('disposition')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_scrap_recovery');
    }
};
