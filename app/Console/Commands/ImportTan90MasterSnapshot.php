<?php

namespace App\Console\Commands;

use App\Services\Tan90\MasterData\ExternalMasterDataImportService;
use App\Services\Tan90\MasterData\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportTan90MasterSnapshot extends Command
{
    protected $signature = 'tan90:import-master-snapshot {path : NDJSON snapshot produced from the supplied ZIP}';

    protected $description = 'Add a repeat-safe, encrypted source snapshot and linked address/contact details to Tan90 master data.';

    public function handle(ExternalMasterDataImportService $importer): int
    {
        $path = $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Snapshot file is missing or unreadable.');

            return self::FAILURE;
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            $this->error('Could not open snapshot file.');

            return self::FAILURE;
        }

        $batches = [];
        $totals = [];
        $lineNumber = 0;
        try {
            $ambiguousItemCodes = $this->ambiguousItemCodes($path);
            while (($line = fgets($stream)) !== false) {
                $lineNumber++;
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $sourceFile = (string) ($record['source_file'] ?? '');
                if ($sourceFile === '') {
                    throw new RuntimeException("Missing source_file at line {$lineNumber}.");
                }
                if ($sourceFile === 'Items.xlsx' && ($record['dataset'] ?? null) === 'items') {
                    $code = mb_strtolower(trim((string) ($record['payload']['SKU'] ?? $record['payload']['Item SKU'] ?? '')));
                    $record['source_code_ambiguous'] = $code !== '' && isset($ambiguousItemCodes[$code]);
                }
                unset($record['source_file']);
                $batches[$sourceFile][] = $record;
                if (count($batches[$sourceFile]) >= 200) {
                    $result = $importer->importRows($sourceFile, $batches[$sourceFile]);
                    $totals[$sourceFile] = $this->sum($totals[$sourceFile] ?? [], $result);
                    $batches[$sourceFile] = [];
                }
            }

            foreach ($batches as $sourceFile => $rows) {
                if ($rows !== []) {
                    $result = $importer->importRows($sourceFile, $rows);
                    $totals[$sourceFile] = $this->sum($totals[$sourceFile] ?? [], $result);
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->error("Import stopped at source line {$lineNumber}; existing source rows remain safely available. Review the application log for the exception details.");

            return self::FAILURE;
        } finally {
            fclose($stream);
        }

        $this->table(['Source file', 'Rows read', 'New source rows', 'Rows linked', 'Current master writes'], array_map(
            fn (string $file, array $count): array => [
                $file,
                $count['processed'],
                $count['inserted'],
                $count['linked'],
                sprintf(
                    'items %d created / %d updated; vendors %d created / %d updated; customers %d created / %d updated',
                    $count['current_masters']['items_created'] ?? 0,
                    $count['current_masters']['items_updated'] ?? 0,
                    $count['current_masters']['vendors_created'] ?? 0,
                    $count['current_masters']['vendors_updated'] ?? 0,
                    $count['current_masters']['customers_created'] ?? 0,
                    $count['current_masters']['customers_updated'] ?? 0,
                ),
            ],
            array_keys($totals), array_values($totals),
        ));

        $sourceFiles = [];
        $masterTotals = [
            'items_created' => 0, 'items_updated' => 0,
            'vendors_created' => 0, 'vendors_updated' => 0,
            'customers_created' => 0, 'customers_updated' => 0,
        ];
        foreach ($totals as $file => $count) {
            $sourceFiles[$file] = [
                'rows_read' => (int) ($count['processed'] ?? 0),
                'source_rows_added' => (int) ($count['inserted'] ?? 0),
                'rows_linked' => DB::table('tan90_master_source_rows')
                    ->where('source_file', $file)
                    ->whereNotNull('canonical_id')
                    ->count(),
            ];
            foreach ($masterTotals as $key => $_) {
                $masterTotals[$key] += (int) ($count['current_masters'][$key] ?? 0);
            }
        }

        $totalRows = array_sum(array_column($sourceFiles, 'rows_read'));
        $newSourceRows = array_sum(array_column($sourceFiles, 'source_rows_added'));
        $linkedRows = array_sum(array_column($sourceFiles, 'rows_linked'));
        $summary = sprintf(
            'Reconciled %s source records across %d Excel workbooks (%s); %s new source rows, %s linked. Current masters: items %s added / %s refreshed, vendors %s added / %s refreshed, customers %s added / %s refreshed.',
            number_format($totalRows), count($sourceFiles), implode(', ', array_keys($sourceFiles)), number_format($newSourceRows), number_format($linkedRows),
            number_format($masterTotals['items_created']), number_format($masterTotals['items_updated']),
            number_format($masterTotals['vendors_created']), number_format($masterTotals['vendors_updated']),
            number_format($masterTotals['customers_created']), number_format($masterTotals['customers_updated']),
        );
        app(AuditLogger::class)->log('IMPORT', null, $summary, [
            'source_files' => $sourceFiles,
            'current_masters' => $masterTotals,
        ], 'Excel Master Data');

        return self::SUCCESS;
    }

    /** @return array<string, true> */
    private function ambiguousItemCodes(string $path): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Could not scan the snapshot for repeated item codes.');
        }

        $namesByCode = [];
        try {
            while (($line = fgets($stream)) !== false) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (($record['source_file'] ?? null) !== 'Items.xlsx' || ($record['dataset'] ?? null) !== 'items') {
                    continue;
                }
                $payload = is_array($record['payload'] ?? null) ? $record['payload'] : [];
                $code = mb_strtolower(trim((string) ($payload['SKU'] ?? $payload['Item SKU'] ?? '')));
                $name = mb_strtolower(trim((string) ($payload['Item Name'] ?? '')));
                if ($code !== '' && $name !== '') {
                    $namesByCode[$code][$name] = true;
                }
            }
        } finally {
            fclose($stream);
        }

        return array_fill_keys(array_keys(array_filter($namesByCode, fn (array $names): bool => count($names) > 1)), true);
    }

    private function sum(array $current, array $next): array
    {
        foreach (['processed', 'inserted', 'linked'] as $key) {
            $current[$key] = ($current[$key] ?? 0) + (int) ($next[$key] ?? 0);
        }
        foreach (['items_created', 'items_updated', 'vendors_created', 'vendors_updated', 'customers_created', 'customers_updated'] as $key) {
            $current['current_masters'][$key] = ($current['current_masters'][$key] ?? 0) + (int) ($next['current_masters'][$key] ?? 0);
        }

        return $current;
    }
}
