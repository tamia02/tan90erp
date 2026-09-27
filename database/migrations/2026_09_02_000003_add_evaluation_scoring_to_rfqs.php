<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->unsignedTinyInteger('technical_score')->nullable()->after('quoted_price');
            $table->unsignedTinyInteger('commercial_score')->nullable()->after('technical_score');
            $table->foreignId('evaluated_by')->nullable()->after('commercial_score')->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable()->after('evaluated_by');
        });
    }

    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evaluated_by');
            $table->dropColumn(['technical_score', 'commercial_score', 'evaluated_at']);
        });
    }
};
