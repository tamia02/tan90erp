<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forge_machine_run_intervals', function (Blueprint $table): void {
            $table->foreignId('process_record_id')->nullable()->after('job_card_id')
                ->constrained('forge_process_records')->cascadeOnDelete();
            $table->unique('process_record_id', 'forge_machine_run_process_record_unique');
            $table->foreignId('job_card_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('forge_machine_run_intervals')->whereNotNull('process_record_id')->delete();

        Schema::table('forge_machine_run_intervals', function (Blueprint $table): void {
            $table->dropUnique('forge_machine_run_process_record_unique');
            $table->dropConstrainedForeignId('process_record_id');
            $table->foreignId('job_card_id')->nullable(false)->change();
        });
    }
};
