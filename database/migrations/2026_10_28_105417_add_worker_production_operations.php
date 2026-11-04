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
        Schema::create('forge_operation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('forge_work_orders');
            $table->string('operation', 30);
            $table->unsignedInteger('cycle_no')->default(1);
            $table->unsignedBigInteger('context_id')->default(0);
            $table->string('status', 20)->default('in_progress');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('started_by')->constrained('users');
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['work_order_id', 'operation', 'cycle_no', 'context_id'], 'forge_run_operation_unique');
            $table->index(['operation', 'status']);
        });
        Schema::table('forge_work_orders', function (Blueprint $table) {
            $table->uuid('creation_key')->nullable()->unique();
        });
        Schema::table('forge_final_qc_results', function (Blueprint $table) {
            $table->unsignedInteger('cycle_no')->default(1);
            $table->index(['work_order_id','cycle_no']);
        });
        Schema::table('forge_bundle_material_lines', fn (Blueprint $table) => $table->string('reason',500)->nullable());
        $current=null; $cycle=1;
        foreach(\Illuminate\Support\Facades\DB::table('forge_final_qc_results')->orderBy('work_order_id')->orderBy('id')->cursor() as $row) {
            if($current!==$row->work_order_id){$current=$row->work_order_id;$cycle=1;}
            \Illuminate\Support\Facades\DB::table('forge_final_qc_results')->where('id',$row->id)->update(['cycle_no'=>$cycle]);
            if($row->result==='rework' && $row->released_at) $cycle++;
        }
        $registry = collect(app(\App\Services\Access\PermissionRegistry::class)->permissions())->keyBy('key');
        foreach (\App\Services\Forge\WorkerProduction::permissionSources() as $key => $source) {
            $permission = \App\Models\Access\AccessPermission::firstOrCreate(['key' => $key], $registry[$key]);
            $sourceId = \Illuminate\Support\Facades\DB::table('access_permissions')->where('key', $source)->value('id');
            if (!$sourceId) continue;
            foreach (['access_role_permissions', 'access_user_permission_overrides'] as $table) {
                if (!Schema::hasTable($table)) continue;
                $owner = $table === 'access_role_permissions' ? 'role_id' : 'user_id';
                foreach (\Illuminate\Support\Facades\DB::table($table)->where('permission_id', $sourceId)->get() as $row) {
                    if (\Illuminate\Support\Facades\DB::table($table)->where($owner, $row->$owner)->where('permission_id', $permission->id)->exists()) continue;
                    $values = (array)$row; unset($values['id']); $values['permission_id'] = $permission->id;
                    \Illuminate\Support\Facades\DB::table($table)->insert($values);
                }
            }
        }
        \Illuminate\Support\Facades\Cache::flush();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recorded operations are business history. Revert the application release
        // without dropping this additive schema; remove it only after exporting data.
    }
};
