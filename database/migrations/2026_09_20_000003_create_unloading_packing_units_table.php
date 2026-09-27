<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unloading_packing_units')) {
            return;
        }

        Schema::create('unloading_packing_units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('unloading_packing_units')->insert(array_map(
            fn (string $name, int $index) => [
                'name' => $name,
                'active' => true,
                'sort_order' => $index,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['Box', 'Bag', 'Drum', 'Piece', 'Unit'],
            range(1, 5),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('unloading_packing_units');
    }
};
