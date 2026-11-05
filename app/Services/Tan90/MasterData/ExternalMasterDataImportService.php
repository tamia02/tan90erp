<?php

namespace App\Services\Tan90\MasterData;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExternalMasterDataImportService
{
    private ?array $canonicalIndexes = null;

    /** @param array<int, array<string, mixed>> $rows */
    public function importRows(string $sourceFile, array $rows): array
    {
        // Rebuild lookup maps for every workbook batch so customers/items created
        // by an earlier batch in the same snapshot are visible to address rows.
        $this->canonicalIndexes = null;
        $now = now();
        $newRows = [];
        $newEntities = [];
        $newAddresses = [];
        $newPeople = [];
        $inserted = 0;
        $currentMasters = [];

        foreach ($rows as $row) {
            $sourceKey = trim((string) ($row['source_key'] ?? ''));
            $dataset = trim((string) ($row['dataset'] ?? ''));
            if ($sourceKey === '' || $dataset === '') {
                throw new \InvalidArgumentException('Every source row needs a source_key and dataset.');
            }

            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $partnerType = (string) ($row['partner_type'] ?? '');
            $sourceEntityId = $this->nullable($row['partner_source_id'] ?? null);
            $partnerName = $this->nullable($row['partner_name'] ?? $row['canonical_name'] ?? null);
            $canonical = $this->resolveCanonical(
                (string) ($row['canonical_type'] ?? ''),
                (string) ($row['canonical_name'] ?? ''),
                (string) ($row['canonical_code'] ?? ''),
                (string) ($row['canonical_sku'] ?? ''),
                $partnerType,
                $sourceEntityId,
            );
            $newRows[] = [
                'source_file' => mb_substr($sourceFile, 0, 160),
                'source_key' => mb_substr($sourceKey, 0, 191),
                'dataset' => mb_substr($dataset, 0, 80),
                'source_row_number' => max(1, (int) ($row['source_row_number'] ?? 1)),
                'display_name' => $this->nullable($row['display_name'] ?? null),
                'canonical_type' => $canonical['type'],
                'canonical_id' => $canonical['id'],
                'payload' => Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (in_array($dataset, ['vendors', 'customers', 'items', 'composite_items'], true) && $sourceEntityId !== null) {
                $newEntities[] = [
                    'partner_type' => $partnerType,
                    'source_entity_id' => mb_substr($sourceEntityId, 0, 100),
                    'source_file' => mb_substr($sourceFile, 0, 160),
                    'display_name' => $partnerName,
                    'canonical_type' => $canonical['type'],
                    'canonical_id' => $canonical['id'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($dataset === 'vendor_addresses' || $dataset === 'customer_addresses') {
                $sourceAddressId = $this->nullable($row['source_address_id'] ?? null);
                if ($sourceAddressId !== null) {
                    $newAddresses[] = $this->addressRow($row, $canonical, $partnerType, $sourceAddressId, $sourceEntityId, $partnerName, $payload, $now);
                }
            }
            if ($dataset === 'vendor_contacts' || $dataset === 'customer_contacts') {
                $sourcePersonId = $this->nullable($row['source_contact_person_id'] ?? null);
                if ($sourcePersonId !== null) {
                    $newPeople[] = $this->contactRow($row, $canonical, $partnerType, $sourcePersonId, $sourceEntityId, $partnerName, $payload, $now);
                }
            }
        }

        DB::transaction(function () use ($sourceFile, $rows, $newRows, $newEntities, $newAddresses, $newPeople, &$inserted, &$currentMasters): void {
            foreach (array_chunk($newRows, 200) as $chunk) {
                $keys = array_column($chunk, 'source_key');
                $file = $chunk[0]['source_file'];
                $existing = DB::table('tan90_master_source_rows')->where('source_file', $file)->whereIn('source_key', $keys)->get(['source_key', 'canonical_id']);
                $existingSet = $existing->keyBy('source_key');
                $fresh = array_values(array_filter($chunk, fn (array $item): bool => ! isset($existingSet[$item['source_key']])));
                if ($fresh !== []) {
                    DB::table('tan90_master_source_rows')->insert($fresh);
                    $inserted += count($fresh);
                }
                foreach ($chunk as $item) {
                    $current = $existingSet->get($item['source_key']);
                    if ($current && $current->canonical_id === null && $item['canonical_id'] !== null) {
                        DB::table('tan90_master_source_rows')->where('source_file', $file)->where('source_key', $item['source_key'])->whereNull('canonical_id')->update([
                            'canonical_type' => $item['canonical_type'],
                            'canonical_id' => $item['canonical_id'],
                            'updated_at' => $item['updated_at'],
                        ]);
                    }
                }
            }

            $this->upsertManagedRows('tan90_master_external_entities', $newEntities, ['partner_type', 'source_entity_id']);
            $this->upsertManagedRows('tan90_master_addresses', $newAddresses, ['partner_type', 'source_address_id']);
            $this->upsertManagedRows('tan90_master_contact_people', $newPeople, ['partner_type', 'source_contact_person_id']);
            $currentMasters = app(CurrentMasterDataWriter::class)->apply($sourceFile, $rows);
        });

        return [
            'processed' => count($rows),
            'inserted' => $inserted,
            'linked' => count(array_filter($newRows, fn (array $row): bool => $row['canonical_id'] !== null)),
            'current_masters' => $currentMasters,
        ];
    }

    /** @return array{type: ?string, id: ?int} */
    private function resolveCanonical(string $type, string $name, string $code, string $sku, string $partnerType, ?string $sourceId): array
    {
        $indexes = $this->canonicalIndexes();
        if ($type === 'vendor' && ($name !== '' || $code !== '')) {
            $id = $indexes['vendor'][mb_strtolower(trim($name))] ?? ($indexes['vendor_code'][mb_strtolower(trim($code))] ?? null);
            if ($id) {
                return ['type' => 'vendor', 'id' => (int) $id];
            }
        }
        if ($type === 'customer' && ($name !== '' || $code !== '')) {
            $id = $indexes['customer'][mb_strtolower(trim($name))] ?? ($indexes['customer_code'][mb_strtolower(trim($code))] ?? null);
            if ($id) {
                return ['type' => 'customer', 'id' => (int) $id];
            }
        }
        if ($type === 'item' && $sku !== '') {
            $id = $indexes['sku'][$sku] ?? null;
            if ($id) {
                return ['type' => 'sku', 'id' => (int) $id];
            }
        }
        if ($type === 'item' && $name !== '') {
            $id = $indexes['item_name'][mb_strtolower(trim($name))] ?? null;
            if ($id) {
                return ['type' => 'sku', 'id' => (int) $id];
            }
        }

        $external = $sourceId !== null && Schema::hasTable('tan90_master_external_entities')
            ? DB::table('tan90_master_external_entities')->where('partner_type', $partnerType)->where('source_entity_id', $sourceId)->first(['canonical_type', 'canonical_id'])
            : null;

        return ['type' => $external?->canonical_type, 'id' => $external?->canonical_id ? (int) $external->canonical_id : null];
    }

    /** @return array{vendor: array<string, int>, vendor_code: array<string, int>, customer: array<string, int>, customer_code: array<string, int>, sku: array<string, int>, item_name: array<string, int>} */
    private function canonicalIndexes(): array
    {
        if ($this->canonicalIndexes !== null) {
            return $this->canonicalIndexes;
        }

        $names = static fn (string $table): array => Schema::hasTable($table)
            ? DB::table($table)->select('id', 'name')->get()->mapWithKeys(fn ($row): array => [mb_strtolower(trim((string) $row->name)) => (int) $row->id])->all()
            : [];
        $codes = static fn (string $table): array => Schema::hasTable($table)
            ? DB::table($table)->whereNotNull('code')->select('id', 'code')->get()->mapWithKeys(fn ($row): array => [mb_strtolower(trim((string) $row->code)) => (int) $row->id])->all()
            : [];
        $skuRows = Schema::hasTable('sku_masters')
            ? DB::table('sku_masters')->select('id', 'product_code', 'sku')->get()
            : collect();
        $this->canonicalIndexes = [
            'vendor' => $names('tan90_vendors'),
            'vendor_code' => $codes('tan90_vendors'),
            'customer' => $names('tan90_customers'),
            'customer_code' => $codes('tan90_customers'),
            'sku' => $skuRows->filter(fn ($row): bool => filled($row->product_code))->mapWithKeys(fn ($row): array => [(string) $row->product_code => (int) $row->id])->all(),
            'item_name' => $skuRows->filter(fn ($row): bool => filled($row->sku))->mapWithKeys(fn ($row): array => [mb_strtolower(trim((string) $row->sku)) => (int) $row->id])->all(),
        ];

        return $this->canonicalIndexes;
    }

    private function addressRow(array $row, array $canonical, string $partnerType, string $sourceId, ?string $partnerSourceId, ?string $partnerName, array $payload, $now): array
    {
        return [
            'partner_type' => $partnerType,
            'source_address_id' => mb_substr($sourceId, 0, 100),
            'partner_source_id' => $partnerSourceId,
            'canonical_type' => $canonical['type'],
            'canonical_id' => $canonical['id'],
            'partner_name' => $partnerName,
            'label' => $this->value($payload, 'Label', 'Address Type'),
            'attention' => $this->value($payload, 'Attention'),
            'address' => $this->textValue($payload, 'Address', 'Billing Address', 'Shipping Address'),
            'street2' => $this->textValue($payload, 'Street2', 'Billing Street2', 'Shipping Street2'),
            'city' => $this->value($payload, 'City', 'Billing City', 'Shipping City'),
            'state' => $this->value($payload, 'State', 'Billing State', 'Shipping State'),
            'country' => $this->value($payload, 'Country', 'Billing Country', 'Shipping Country'),
            'postal_code' => $this->value($payload, 'Code', 'Billing Code', 'Shipping Code'),
            'phone' => $this->value($payload, 'Phone', 'Billing Phone', 'Shipping Phone'),
            'fax' => $this->value($payload, 'Fax', 'Billing Fax', 'Shipping Fax'),
            'tax_registration_no' => $this->value($payload, 'Tax Registration No', 'GST Identification Number (GSTIN)'),
            'place_of_supply' => $this->value($payload, 'Place Of Supply', 'Source of Supply'),
            'business_legal_name' => $this->value($payload, 'Business Legal Name'),
            'business_trade_name' => $this->value($payload, 'Business Trade Name'),
            'source_payload' => Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function contactRow(array $row, array $canonical, string $partnerType, string $sourceId, ?string $partnerSourceId, ?string $partnerName, array $payload, $now): array
    {
        return [
            'partner_type' => $partnerType,
            'source_contact_person_id' => mb_substr($sourceId, 0, 100),
            'partner_source_id' => $partnerSourceId,
            'canonical_type' => $canonical['type'],
            'canonical_id' => $canonical['id'],
            'partner_name' => $partnerName,
            'salutation' => $this->value($payload, 'Salutation'),
            'first_name' => $this->value($payload, 'First Name'),
            'last_name' => $this->value($payload, 'Last Name'),
            'display_name' => $this->value($payload, 'Display Name', 'Contact Name'),
            'email' => $this->value($payload, 'EmailID', 'Email'),
            'phone' => $this->value($payload, 'Phone'),
            'mobile_phone' => $this->value($payload, 'MobilePhone', 'Mobile'),
            'department' => $this->value($payload, 'Department'),
            'designation' => $this->value($payload, 'Designation'),
            'is_primary' => in_array(mb_strtolower((string) $this->value($payload, 'Is Primary')), ['yes', 'true', '1'], true),
            'source_payload' => Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function upsertManagedRows(string $table, array $rows, array $uniqueBy): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            if ($chunk !== []) {
                DB::table($table)->upsert($chunk, $uniqueBy, array_values(array_diff(array_keys($chunk[0]), [...$uniqueBy, 'created_at'])));
            }
        }
    }

    private function value(array $payload, string ...$keys): ?string
    {
        $value = $this->textValue($payload, ...$keys);

        return $value === null ? null : mb_substr($value, 0, 255);
    }

    private function textValue(array $payload, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return null;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
