<?php

namespace App\Console\Commands;

use App\Models\TermsTemplate;
use App\Services\Zoho\ZohoReadClient;
use Illuminate\Console\Command;

// Fills Terms & Notes templates from the client's real Zoho (read-only):
// vendors - the latest PO terms/notes per "Purchase Nature"; customers - the
// latest Sales Order terms/notes per "Business Vertical"; plus a default per
// side (the most used). Templates a person has edited are never overwritten.
class PullZohoTerms extends Command
{
    protected $signature = 'zoho:pull-terms {--max=80 : Most document details to read per side}';

    protected $description = 'Read Terms & Conditions and Notes by category from Zoho POs / Sales Orders into Terms & Notes templates.';

    public function handle(ZohoReadClient $zoho): int
    {
        if (! $zoho->configured()) {
            $this->error('Zoho is not configured.');

            return self::FAILURE;
        }

        foreach ([
            'vendor' => ['/purchaseorders', 'purchaseorders', 'purchaseorder_id', 'purchaseorder', 'Purchase Nature'],
            'customer' => ['/salesorders', 'salesorders', 'salesorder_id', 'salesorder', 'Business Vertical'],
        ] as $party => [$path, $listKey, $idKey, $one, $categoryLabel]) {
            $found = [];
            $all = [];
            $reads = 0;
            for ($page = 1; $page <= 5 && $reads < (int) $this->option('max'); $page++) {
                $list = $zoho->get($path, ['page' => $page, 'per_page' => 50, 'sort_column' => 'date', 'sort_order' => 'D']);
                foreach ($list[$listKey] ?? [] as $row) {
                    if ($reads >= (int) $this->option('max')) {
                        break;
                    }
                    $doc = $zoho->get("{$path}/{$row[$idKey]}")[$one] ?? [];
                    $reads++;
                    $terms = trim((string) ($doc['terms'] ?? ''));
                    $notes = trim((string) ($doc['notes'] ?? ''));
                    if ($terms === '' && $notes === '') {
                        continue;
                    }
                    $category = collect($doc['custom_fields'] ?? [])->first(fn ($f) => ($f['label'] ?? '') === $categoryLabel)['value'] ?? null;
                    if (! $category && $party === 'customer') {
                        // Business Vertical is often left empty on SOs; the number says it: CAASSO... = CAAS, SO... = Sales.
                        $prefix = strtoupper(preg_replace('/\d.*$/', '', (string) ($doc['salesorder_number'] ?? '')));
                        $category = match (true) {
                            str_starts_with($prefix, 'CAAS') => 'CAAS',
                            str_starts_with($prefix, 'RND') || str_starts_with($prefix, 'R&D') => 'R&D',
                            str_starts_with($prefix, 'SMP') || str_starts_with($prefix, 'SAMPLE') => 'Sample',
                            str_starts_with($prefix, 'RENT') => 'Rental',
                            $prefix === 'SO' => 'Sales',
                            default => null,
                        };
                    }
                    $all[] = [$terms, $notes];
                    if ($category && ! isset($found[$category])) {
                        $found[$category] = [$terms, $notes, $doc[$listKey === 'purchaseorders' ? 'purchaseorder_number' : 'salesorder_number'] ?? ''];
                    }
                }
                if (empty($list['page_context']['has_more_page'])) {
                    break;
                }
            }

            $default = collect($all)->countBy(fn ($pair) => json_encode($pair))->sortDesc()->keys()->first();
            if ($default) {
                [$t, $n] = json_decode($default, true);
                $this->save($party, null, ucfirst($party).'s - default (from Zoho)', $t, $n);
            }
            foreach ($found as $category => [$t, $n, $doc]) {
                $this->save($party, $category, "{$category} (from Zoho {$doc})", $t, $n);
            }
            $this->info(ucfirst($party).": read {$reads} documents, ".count($found).' categories'.($default ? ' + default' : '').'.');
        }

        return self::SUCCESS;
    }

    private function save(string $party, ?string $category, string $name, string $terms, string $notes): void
    {
        $existing = TermsTemplate::where('party_type', $party)->where('source', 'zoho')
            ->when($category, fn ($q) => $q->where('category', $category), fn ($q) => $q->whereNull('category'))->first();
        if ($existing && $existing->updated_by) {
            return; // edited by a person - keep their version
        }
        TermsTemplate::updateOrCreate(['id' => $existing?->id], [
            'party_type' => $party, 'category' => $category, 'name' => $name,
            'terms' => $terms ?: null, 'notes' => $notes ?: null, 'active' => true, 'source' => 'zoho',
        ]);
    }
}
