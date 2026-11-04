<?php

namespace App\Services\Forge;

use App\Models\Workspace\WorkspaceTask;

class ProductionTaskService
{
    public function open(string $key, string $title, string $permission, int $siteId, int $actorId, object $linked, ?string $description = null): void
    {
        $task = WorkspaceTask::firstOrCreate(['event_key' => $key], [
            'title' => $title, 'description' => $description, 'module' => 'production',
            'linked_type' => $linked::class, 'linked_id' => $linked->id,
            'site_id' => $siteId, 'target_permission' => $permission,
            'created_by' => $actorId, 'status' => 'open', 'priority' => 'high',
        ]);
        if ($task->wasRecentlyCreated) {
            $task->events()->create(['user_id' => $actorId, 'action' => 'created']);
        }
    }

    public function done(string $key, ?int $actorId = null): void
    {
        $task = WorkspaceTask::where('event_key', $key)->where('status', '!=', 'completed')->first();
        if ($task) {
            $task->update(['status' => 'completed', 'completed_at' => now()]);
            $task->events()->create(['user_id' => $actorId, 'action' => 'completed_by_erp_action']);
        }
    }
}
