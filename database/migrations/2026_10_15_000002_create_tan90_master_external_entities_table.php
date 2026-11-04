<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_master_external_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('partner_type', 20);
            $table->string('source_entity_id', 100);
            $table->string('source_file', 160);
            $table->string('display_name')->nullable()->index();
            $table->string('canonical_type', 40)->nullable();
            $table->unsignedBigInteger('canonical_id')->nullable();
            $table->timestamps();
            $table->unique(['partner_type', 'source_entity_id'], 'tan90_master_external_entities_source_unique');
            $table->index(['canonical_type', 'canonical_id'], 'tan90_master_external_entities_canonical_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_master_external_entities');
    }
};
