<?php

namespace App\Services\Tan90\MasterData;

use App\Jobs\Tan90\MasterData\ProcessMasterDataImport;
use App\Models\Tan90\MasterData\DataImportJob;
use App\Models\Tan90\MasterData\DataImportRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * CSV import pipeline: upload -> auto column map -> validate every row ->
 * duplicate detection -> preview (paginated 50/page in the UI) -> commit.
 * Idempotent per
 * entity_type + file_hash (see tan90_data_import_jobs' unique index): a
 * second upload of the same file returns the existing job instead of
 * re-importing it. Row-level idempotency comes from source_row_key
 * (the entity's natural key), checked again at commit time.
 *
 * Jobs above QUEUE_THRESHOLD rows are committed asynchronously via
 * ProcessMasterDataImport rather than inline, per the "queue large imports"
 * requirement - this needs a queue worker running (`php artisan queue:work`).
 */
class CsvImportService
{
    public const QUEUE_THRESHOLD = 200;

    public function __construct(
        private readonly EntityRegistry $registry,
        private readonly EntityValidator $validator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function upload(UploadedFile $file, string $entitySlug, User $actor): DataImportJob
    {
        $entity = $this->registry->get($entitySlug);
        if (empty($entity['importable'])) {
            abort(422, "The {$entity['title']} entity does not support CSV import.");
        }

        $hash = hash_file('sha256', $file->getRealPath());

        $existing = DataImportJob::where('entity_type', $entitySlug)->where('file_hash', $hash)->first();
        if ($existing) {
            return $existing; // idempotent: same file + entity already has a job
        }

        $storedPath = $file->store('tan90-imports', 'local');

        $job = DataImportJob::create([
            'entity_type' => $entitySlug,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storedPath,
            'file_hash' => $hash,
            'started_by' => $actor->id,
            'result' => 'pending',
        ]);

        $this->analyze($job, $entity);

        return $job;
    }

    private function analyze(DataImportJob $job, array $entity): void
    {
        $rows = $this->readCsv($job->storage_path);
        if (empty($rows)) {
            $job->update(['result' => 'failed']);
            return;
        }

        $header = array_map('trim', array_shift($rows));
        $columnMap = $this->autoMapColumns($header, $entity);
        $rules = $this->validator->rules($entity);

        $valid = 0;
        $invalid = 0;
        $duplicate = 0;
        $seenKeys = [];
        $codeField = $entity['code'];
        $modelClass = $entity['model'];

        DB::transaction(function () use ($rows, $header, $columnMap, $rules, $job, $codeField, $modelClass, &$valid, &$invalid, &$duplicate, &$seenKeys) {
            foreach ($rows as $index => $row) {
                $raw = array_combine($header, array_pad($row, count($header), null));
                $mapped = [];
                foreach ($columnMap as $field => $column) {
                    $mapped[$field] = $column ? ($raw[$column] ?? null) : null;
                }

                $rowKey = $mapped[$codeField] ?? null;
                $errors = [];
                $status = 'valid';

                $validated = Validator::make($mapped, $rules);
                if ($validated->fails()) {
                    $errors = $validated->errors()->toArray();
                    $status = 'invalid';
                    $invalid++;
                } elseif ($rowKey && (isset($seenKeys[$rowKey]) || $modelClass::withTrashed()->where($codeField, $rowKey)->exists())) {
                    $status = 'duplicate';
                    $duplicate++;
                } else {
                    $valid++;
                }

                if ($rowKey) {
                    $seenKeys[$rowKey] = true;
                }

                DataImportRow::create([
                    'tan90_data_import_job_id' => $job->id,
                    'row_number' => $index + 2, // +1 for header, +1 for 1-based
                    'source_row_key' => $rowKey,
                    'raw_data' => $raw,
                    'mapped_data' => $mapped,
                    'errors' => $errors ?: null,
                    'status' => $status,
                ]);
            }
        });

        $job->update([
            'column_map' => $columnMap,
            'total_rows' => count($rows),
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
            'duplicate_rows' => $duplicate,
            'result' => 'previewed',
        ]);
    }

    public function commit(DataImportJob $job, User $actor): DataImportJob
    {
        if ($job->valid_rows > self::QUEUE_THRESHOLD) {
            $job->update(['result' => 'queued']);
            ProcessMasterDataImport::dispatch($job->id, $actor->id);

            return $job;
        }

        return $this->applyValidRows($job, $actor);
    }

    public function applyValidRows(DataImportJob $job, User $actor): DataImportJob
    {
        $entity = $this->registry->get($job->entity_type);
        $modelClass = $entity['model'];

        DB::transaction(function () use ($job, $modelClass) {
            $job->rows()->where('status', 'valid')->each(function (DataImportRow $row) use ($modelClass) {
                $record = $modelClass::create(array_merge($row->mapped_data, ['approval_status' => 'draft']));
                $row->update(['status' => 'imported', 'matched_entity_id' => $record->id]);
            });
        });

        $job->update([
            'result' => $job->invalid_rows > 0 || $job->duplicate_rows > 0 ? 'completed_with_warnings' : 'completed',
            'completed_at' => now(),
        ]);

        $this->auditLogger->logSystem(
            'IMPORT',
            $entity['title'],
            "Imported {$job->valid_rows} {$entity['title']} rows from {$job->original_filename} (skipped {$job->invalid_rows} invalid, {$job->duplicate_rows} duplicate)."
        );

        return $job->refresh();
    }

    public function rejectedRowsCsv(DataImportJob $job): string
    {
        $rows = $job->rows()->whereIn('status', ['invalid', 'duplicate'])->get();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['row_number', 'status', 'errors', ...array_keys($job->column_map ?? [])]);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row->row_number,
                $row->status,
                json_encode($row->errors),
                ...array_values($row->mapped_data ?? []),
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function autoMapColumns(array $header, array $entity): array
    {
        $map = [];
        foreach ($entity['fields'] as $field) {
            $needle = strtolower($field['name']);
            $labelNeedle = strtolower(str_replace(' ', '', $field['label']));
            $match = null;
            foreach ($header as $column) {
                $clean = strtolower(str_replace([' ', '_', '-'], '', $column));
                if ($clean === $needle || $clean === $labelNeedle) {
                    $match = $column;
                    break;
                }
            }
            $map[$field['name']] = $match;
        }

        return $map;
    }

    private function readCsv(string $storagePath): array
    {
        $stream = Storage::disk('local')->readStream($storagePath);
        $rows = [];
        while (($line = fgetcsv($stream)) !== false) {
            $rows[] = $line;
        }
        fclose($stream);

        return $rows;
    }
}
