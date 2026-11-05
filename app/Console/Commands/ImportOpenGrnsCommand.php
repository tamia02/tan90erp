<?php

namespace App\Console\Commands;

use App\Models\GateEntry;
use App\Models\GrnRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImportOpenGrnsCommand extends Command
{
    protected $signature = 'tan90:import-open-grns {file : JSON file containing gate/GRN/package records} {--dry-run : Validate and count without writing}';

    protected $description = 'Import open GRNs/packages while preserving the source workflow status.';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("Import file not found: {$path}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        $rows = is_array($payload) && array_is_list($payload) ? $payload : ($payload['records'] ?? []);
        if (! is_array($rows)) {
            $this->error('Expected a JSON array or an object with a records array.');

            return self::FAILURE;
        }

        $written = 0;
        DB::transaction(function () use ($rows, &$written): void {
            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    throw new \InvalidArgumentException("Record ".($index + 1).' must be an object.');
                }

                $gateData = (array) ($row['gate'] ?? $row);
                $gateNo = trim((string) ($gateData['gate_no'] ?? $gateData['gate'] ?? ''));
                if ($gateNo === '') {
                    throw new \InvalidArgumentException("Record ".($index + 1).' is missing gate_no.');
                }

                if ($this->option('dry-run')) {
                    $written++;
                    continue;
                }

                $gate = GateEntry::updateOrCreate(['gate_no' => $gateNo], [
                    'entry_type' => $gateData['entry_type'] ?? 'inward',
                    'po_number' => $gateData['po_number'] ?? null,
                    'vendor_name' => $gateData['vendor_name'] ?? null,
                    'vendor_gst' => $gateData['vendor_gst'] ?? null,
                    'invoice_number' => $gateData['invoice_number'] ?? null,
                    'invoice_qty' => $gateData['invoice_qty'] ?? null,
                    'invoice_amount' => $gateData['invoice_amount'] ?? null,
                    'rate' => $gateData['rate'] ?? null,
                    'material' => $gateData['material'] ?? null,
                    'product_lines' => $gateData['product_lines'] ?? ($row['product_lines'] ?? null),
                    'vehicle_number' => $gateData['vehicle_number'] ?? 'IMPORTED',
                    'driver_name' => $gateData['driver_name'] ?? 'Imported record',
                    'transporter' => $gateData['transporter'] ?? null,
                    'location' => $gateData['location'] ?? 'Imported location',
                    'gps' => $gateData['gps'] ?? null,
                    'bill_scanned' => (bool) ($gateData['bill_scanned'] ?? false),
                    'remarks' => $gateData['remarks'] ?? 'Imported open GRN/package; source status preserved.',
                    // Never infer a new stage from the payload. The source
                    // status is the continuation point for the normal flow.
                    'status' => $gateData['status'] ?? 'pending_validation',
                    'sla_deadline' => $gateData['sla_deadline'] ?? Carbon::now()->addDay(),
                ]);

                $grnData = (array) ($row['grn'] ?? []);
                if ($grnData !== []) {
                    $grn = GrnRecord::updateOrCreate(['gate_entry_id' => $gate->id], [
                        'sku' => $grnData['sku'] ?? $gate->material ?? 'Imported SKU',
                        'po_qty' => (int) ($grnData['po_qty'] ?? $gate->invoice_qty ?? 0),
                        'invoice_qty' => (int) ($grnData['invoice_qty'] ?? $gate->invoice_qty ?? 0),
                        'physical_received' => (int) ($grnData['physical_received'] ?? 0),
                        'accepted_qty' => (int) ($grnData['accepted_qty'] ?? 0),
                        'qc_hold_qty' => (int) ($grnData['qc_hold_qty'] ?? 0),
                        'defective_qty' => (int) ($grnData['defective_qty'] ?? 0),
                        'rejected_qty' => (int) ($grnData['rejected_qty'] ?? 0),
                        'missing_qty' => (int) ($grnData['missing_qty'] ?? 0),
                        'qc_reasons' => $grnData['qc_reasons'] ?? null,
                        'suggested_bin' => $grnData['suggested_bin'] ?? $gate->final_bin ?? 'IMPORTED',
                        'posted' => (bool) ($grnData['posted'] ?? in_array($gate->status, ['grn_posted', 'closed'], true)),
                    ]);

                    foreach ((array) ($grnData['lines'] ?? $row['lines'] ?? []) as $line) {
                        if (! is_array($line) || ! filled($line['sku'] ?? null)) {
                            continue;
                        }
                        $grn->lines()->updateOrCreate(['sku' => $line['sku']], [
                            'gate_entry_id' => $gate->id,
                            'po_qty' => (int) ($line['po_qty'] ?? 0),
                            'invoice_qty' => (int) ($line['invoice_qty'] ?? 0),
                            'physical_received' => (int) ($line['physical_received'] ?? 0),
                            'accepted_qty' => (int) ($line['accepted_qty'] ?? 0),
                            'qc_hold_qty' => (int) ($line['qc_hold_qty'] ?? 0),
                            'defective_qty' => (int) ($line['defective_qty'] ?? 0),
                            'rejected_qty' => (int) ($line['rejected_qty'] ?? 0),
                            'missing_qty' => (int) ($line['missing_qty'] ?? 0),
                            'qc_reasons' => $line['qc_reasons'] ?? null,
                            'suggested_bin' => $line['suggested_bin'] ?? $grn->suggested_bin,
                        ]);
                    }
                }

                $written++;
            }
        });

        $this->info(($this->option('dry-run') ? 'Validated ' : 'Imported ').$written.' open GRN/package record(s).');
        $this->line('Status values are taken from the source record; no workflow stage was inferred.');

        return self::SUCCESS;
    }
}
