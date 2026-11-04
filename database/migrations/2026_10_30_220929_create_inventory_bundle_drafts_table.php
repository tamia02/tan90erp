<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_bundle_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('draft_key')->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('location_id')->nullable()->constrained('tan90_locations');
            $table->json('payload');
            $table->foreignId('production_id')->nullable()->constrained('inventory_productions');
            $table->timestamps();
            $table->index(['created_by', 'production_id', 'updated_at'], 'bundle_drafts_owner_pending');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_bundle_drafts');
    }
};
