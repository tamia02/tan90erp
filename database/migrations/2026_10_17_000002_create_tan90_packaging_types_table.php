<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_packaging_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('requires_box_size')->default(false);
            $table->string('status')->default('active');
            $table->string('approval_status')->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        DB::table('tan90_packaging_types')->insertOrIgnore([
            ['code' => 'BOX', 'name' => 'Box', 'requires_box_size' => true, 'status' => 'active', 'approval_status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CARTON', 'name' => 'Carton', 'requires_box_size' => false, 'status' => 'active', 'approval_status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BAG', 'name' => 'Bag', 'requires_box_size' => false, 'status' => 'active', 'approval_status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'DRUM', 'name' => 'Drum', 'requires_box_size' => false, 'status' => 'active', 'approval_status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CRATE', 'name' => 'Crate', 'requires_box_size' => false, 'status' => 'active', 'approval_status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PALLET', 'name' => 'Pallet', 'requires_box_size' => false, 'status' => 'active', 'approval_status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_packaging_types');
    }
};
