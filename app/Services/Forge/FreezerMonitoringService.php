<?php

namespace App\Services\Forge;

use App\Models\Forge\Batch;
use App\Models\Forge\Freezer;
use App\Models\Forge\FreezerLog;
use App\Models\Forge\FreezerReading;
use App\Models\Workspace\WorkspaceException;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FreezerMonitoringService
{
    public function assignBatch(Freezer $freezer, Batch $batch): FreezerLog
    {
        if ($freezer->openLog()) {
            throw ValidationException::withMessages(['freezer' => 'Freezer already has a batch conditioning in it.']);
        }

        if (FreezerLog::where('batch_id', $batch->id)->whereNull('ended_at')->exists()) {
            throw ValidationException::withMessages(['batch' => 'This batch is already conditioning in another freezer.']);
        }

        return DB::transaction(function () use ($freezer, $batch) {
            $log = FreezerLog::create([
                'freezer_id' => $freezer->id,
                'batch_id' => $batch->id,
                'started_at' => now(),
            ]);

            $freezer->update(['state' => 'running']);

            AuditLogger::log('Batch assigned to freezer', "{$batch->batch_number} -> {$freezer->name}", $log);

            return $log;
        });
    }

    public function releaseBatch(FreezerLog $log): FreezerLog
    {
        abort_unless($log->ended_at === null, 422, 'This freezer log is already closed.');

        DB::transaction(function () use ($log) {
            $log->update(['ended_at' => now()]);
            $log->freezer->update(['state' => 'idle']);
        });

        AuditLogger::log('Batch released from freezer', "{$log->batch->batch_number} <- {$log->freezer->name} ({$log->durationMinutes()} min)", $log);

        return $log->refresh();
    }

    /**
     * Records a reading and, when it breaches the freezer's configured range,
     * raises a workspace exception so the excursion surfaces in the same
     * Alerts & Exceptions queue every other module's problems land in,
     * instead of a temperature log nobody is watching.
     */
    public function recordReading(Freezer $freezer, float $temperature, ?float $humidity = null): FreezerReading
    {
        $isAlert = $freezer->isOutOfRange($temperature);

        $reading = FreezerReading::create([
            'freezer_id' => $freezer->id,
            'temperature' => $temperature,
            'humidity' => $humidity,
            'is_alert' => $isAlert,
            'recorded_at' => now(),
        ]);

        if ($isAlert) {
            $this->raiseTemperatureException($freezer, $reading);
        }

        return $reading;
    }

    private function raiseTemperatureException(Freezer $freezer, FreezerReading $reading): void
    {
        // One open exception per freezer at a time — a sensor reporting every
        // few minutes must not flood the queue with a duplicate row per reading
        // while the excursion is still ongoing and already being worked.
        $alreadyOpen = WorkspaceException::where('linked_type', Freezer::class)
            ->where('linked_id', $freezer->id)
            ->where('status', '!=', 'resolved')
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        $batch = $freezer->openLog()?->batch;
        $subject = $batch ? " (batch {$batch->batch_number})" : '';

        $exception = WorkspaceException::create([
            'title' => "{$freezer->name} temperature out of range: {$reading->temperature}°C{$subject}",
            'category' => 'temperature_excursion',
            'module' => 'forge.freezer',
            'severity' => 'critical',
            'linked_type' => Freezer::class,
            'linked_id' => $freezer->id,
            'status' => 'open',
        ]);
        $exception->events()->create(['action' => 'raised']);

        AuditLogger::log('Freezer temperature exception raised', $exception->title, $exception);
    }
}
