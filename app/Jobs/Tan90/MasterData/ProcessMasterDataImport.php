<?php

namespace App\Jobs\Tan90\MasterData;

use App\Models\Tan90\MasterData\DataImportJob;
use App\Models\User;
use App\Services\Tan90\MasterData\CsvImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Applies the valid rows of a large (> CsvImportService::QUEUE_THRESHOLD row)
 * import job asynchronously. Requires a queue worker: `php artisan queue:work`.
 */
class ProcessMasterDataImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $jobId, public readonly int $actorId)
    {
    }

    public function handle(CsvImportService $importer): void
    {
        $job = DataImportJob::findOrFail($this->jobId);
        $actor = User::findOrFail($this->actorId);

        $importer->applyValidRows($job, $actor);
    }
}
