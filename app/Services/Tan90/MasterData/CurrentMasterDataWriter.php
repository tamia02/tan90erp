<?php

namespace App\Services\Tan90\MasterData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CurrentMasterDataWriter
{
    /** @param array<int, array<string, mixed>> $rows */
    public function apply(string $sourceFile, array $rows): array
    {
        $counts = [
            'items_created' => 0,
            'items_updated' => 0,
            'vendors_created' => 0,
            'vendors_updated' => 0,
            'customers_created' => 0,
            'customers_updated' => 0,
        ];

        foreach ($rows as $row) {
            $dataset = (string) ($row['dataset'] ?? '');
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];

            if (in_array($dataset, ['items', 'composite_items'], true) && Schema::hasTable('sku_masters') && Schema::hasTable('inventory_items')) {
                $result = $this->writeItem($row, $payload, $dataset === 'composite_items');
                $counts[$result['created'] ? 'items_created' : 'items_updated']++;
                $this->linkSourceRow($sourceFile, $row, 'sku', $result['id']);
            }

            if ($dataset === 'vendors' && Schema::hasTable('tan90_vendors')) {
                $result = $this->writeVendor($sourceFile, $row, $payload);
                $counts[$result['created'] ? 'vendors_created' : 'vendors_updated']++;
                $this->linkSourceRow($sourceFile, $row, 'vendor', $result['id']);
            }

            if ($dataset === 'customers' && Schema::hasTable('tan90_customers')) {
                $result = $this->writeCustomer($sourceFile, $row, $payload);
                $counts[$result['created'] ? 'customers_created' : 'customers_updated']++;
                $this->linkSourceRow($sourceFile, $row, 'customer', $result['id']);
            }
        }

        return $counts;
    }

    private function writeItem(array $row, array $payload, bool $composite = false): array
    {
        $itemName = $this->value($payload, 'Item Name', 'Composite Item Name');
        if ($itemName === null) {
            return ['created' => false, 'id' => null];
        }

        $code = $this->value($payload, 'SKU', 'Item SKU');
        $ambiguousCode = (bool) ($row['source_code_ambiguous'] ?? false);
        $skuMaster = null;
        if ($code !== null) {
            $skuMaster = DB::table('sku_masters')->where('product_code', $code)->get()
                ->first(fn ($candidate): bool => $candidate->product_code === $code
                    && (! $ambiguousCode || $this->sameItemName($candidate->sku, $itemName)));
        }
        if (! $skuMaster && ($row['canonical_type'] ?? null) === 'item' && ! empty($row['canonical_id'])) {
            $candidate = DB::table('sku_masters')->where('id', $row['canonical_id'])->first();
            if ($candidate && ($code === null || empty($candidate->product_code) || $candidate->product_code === $code)
                && (! $ambiguousCode || $this->sameItemName($candidate->sku, $itemName))) {
                $skuMaster = $candidate;
            }
        }
        if (! $skuMaster) {
            $sameName = DB::table('sku_masters')->whereRaw('LOWER(TRIM(sku)) = ?', [mb_strtolower(trim($itemName))])->get();
            if ($sameName->count() === 1 && ($code === null || empty($sameName->first()->product_code) || $sameName->first()->product_code === $code)
                && (! $ambiguousCode || $this->sameItemName($sameName->first()->sku, $itemName))) {
                $skuMaster = $sameName->first();
            }
        }

        $created = $skuMaster === null;
        $oldItemName = $skuMaster->sku ?? null;
        $inventory = $oldItemName !== null ? DB::table('inventory_items')->where('sku', $oldItemName)->first() : null;
        if ($inventory && $code !== null && filled($inventory->code) && $inventory->code !== $code) {
            $inventory = null;
        }
        if (! $inventory && $code !== null) {
            $inventory = DB::table('inventory_items')->where('code', $code)->get()
                ->first(fn ($candidate): bool => $candidate->code === $code
                    && (! $ambiguousCode || $this->sameItemName($candidate->sku, $itemName)));
        }

        $itemType = $composite ? 'fg' : $this->itemType($payload);
        $unit = $this->unit($this->value($payload, 'Usage unit', 'Unit Name', 'Unit'));
        $inventorySku = $itemName;
        $inventoryNameConflict = DB::table('inventory_items')->where('sku', $itemName)->first();
        $skuNameConflict = DB::table('sku_masters')->where('sku', $itemName)->first();
        $inventoryNameIsTaken = $inventoryNameConflict && (! $inventory || (int) $inventoryNameConflict->id !== (int) $inventory->id);
        $masterNameIsTaken = $skuNameConflict && (! $skuMaster || (int) $skuNameConflict->id !== (int) $skuMaster->id);
        if ($inventoryNameIsTaken || $masterNameIsTaken) {
            $alreadyDisambiguated = $code !== null
                && $skuMaster
                && $inventory
                && $skuMaster->sku === $inventory->sku
                && str_starts_with($skuMaster->sku, $itemName.' ['.$code.']');
            $inventorySku = $alreadyDisambiguated ? $skuMaster->sku : $this->uniqueInventorySku($itemName, $code);
        }
        $category = $composite ? 'Zoho — Composite Item (FG)' : match ($itemType) {
            'fg' => 'Zoho — Composite Item (FG)',
            'pm' => 'Zoho — Packaging Material (PM)',
            'trading' => 'Zoho — Trading Good',
            default => 'Zoho — Raw Material (RM)',
        };
        $now = now();
        $values = [
            'sku' => $inventorySku,
            'category' => $category,
            'unit' => $unit,
            'mapped' => true,
            'active' => $this->isActive($this->value($payload, 'Status')),
            'zoho_item_type' => $this->slug($this->value($payload, 'Product Type', 'Item Type')),
            'zoho_is_composite' => $composite || $this->isTrue($this->value($payload, 'Is Combo Product')),
            'zoho_tags' => $this->joinValues($payload, 'TAG.Business Vertical', 'TAG.Category', 'TAG.Location'),
            'zoho_hsn' => $this->value($payload, 'HSN/SAC'),
            'updated_at' => $now,
        ];

        if ($code !== null) {
            $values['product_code'] = $code;
        }
        foreach ([
            'manufacturer' => ['Manufacturer'],
            'vendor_name' => ['Vendor', 'Preferred Vendor'],
            'unit_price' => ['Rate', 'Selling Price'],
            'tax' => ['Inter State Tax Rate', 'Intra State Tax Rate'],
            'taxable' => ['Taxable'],
            'description' => ['Description', 'Item Description', 'Sales Description', 'Purchase Description'],
            'reorder_level' => ['Reorder Point'],
            'intra_state_tax_name' => ['Intra State Tax Name'],
            'intra_state_tax_rate' => ['Intra State Tax Rate'],
            'intra_state_tax_type' => ['Intra State Tax Type'],
            'inter_state_tax_name' => ['Inter State Tax Name'],
            'inter_state_tax_rate' => ['Inter State Tax Rate'],
            'inter_state_tax_type' => ['Inter State Tax Type'],
            'taxability_type' => ['Taxability Type'],
            'tax_exemption_reason' => ['Exemption Reason'],
        ] as $column => $sourceKeys) {
            $value = match (true) {
                $column === 'description' => $this->textValue($payload, ...$sourceKeys),
                in_array($column, ['unit_price', 'tax', 'reorder_level', 'intra_state_tax_rate', 'inter_state_tax_rate'], true) => $this->number($this->value($payload, ...$sourceKeys)),
                $column === 'taxable' => $this->isTrue($this->value($payload, ...$sourceKeys)),
                default => $this->value($payload, ...$sourceKeys),
            };

            // A duplicate source row with no SKU is weaker evidence than a
            // coded master row. Do not let its default zero erase a known
            // non-zero price for the coded item.
            if ($column === 'unit_price' && $value === 0.0 && $code === null
                && filled($skuMaster->product_code ?? null) && (float) ($skuMaster->unit_price ?? 0) > 0) {
                continue;
            }

            if ($value !== null) {
                $values[$column] = $value;
            }
        }
        $taxable = $this->value($payload, 'Taxable');
        if ($taxable !== null) {
            $values['taxable'] = $this->isTrue($taxable);
        }
        $values = array_filter($values, fn ($value): bool => $value !== null && $value !== '');

        if ($created) {
            $values['created_at'] = $now;
            $id = DB::table('sku_masters')->insertGetId($values);
            $skuMaster = DB::table('sku_masters')->where('id', $id)->first();
        } else {
            DB::table('sku_masters')->where('id', $skuMaster->id)->update($values);
            $id = (int) $skuMaster->id;
        }

        $inventoryCreated = false;
        if (! $inventory) {
            $openingQty = $this->number($this->value($payload, 'Opening Stock'));
            DB::table('inventory_items')->insert([
                'sku' => $inventorySku,
                'code' => $code,
                'name' => $itemName,
                'item_type' => $itemType,
                'unit' => $unit,
                'opening_qty' => $openingQty !== null && $openingQty > 0 ? $openingQty : 0,
                'active' => $this->isActive($this->value($payload, 'Status')),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inventoryCreated = true;
        } else {
            $inventoryValues = [
                'sku' => $inventorySku,
                'item_type' => $itemType,
                'unit' => $unit,
                'active' => $this->isActive($this->value($payload, 'Status')),
                'updated_at' => $now,
            ];
            if (! filled($inventory->name) || ($code !== null && $inventory->name === $code)) {
                $inventoryValues['name'] = $itemName;
            }
            if ($code !== null) {
                $inventoryValues['code'] = $code;
            }
            $openingQty = $this->number($this->value($payload, 'Opening Stock'));
            $hasMovementHistory = Schema::hasTable('inventory_movements')
                && DB::table('inventory_movements')->where('item_id', $inventory->id)->exists();
            $hasReceiptHistory = Schema::hasTable('ledger_entries')
                && DB::table('ledger_entries')->where('sku', $inventory->sku)->where('bucket', 'available')->exists();
            if ($openingQty !== null && $openingQty > 0 && (float) $inventory->opening_qty === 0.0
                && ! $hasMovementHistory && ! $hasReceiptHistory) {
                $inventoryValues['opening_qty'] = $openingQty;
            }
            DB::table('inventory_items')->where('id', $inventory->id)->update($inventoryValues);
        }

        return ['created' => $created || $inventoryCreated, 'id' => $id];
    }

    private function writeCustomer(string $sourceFile, array $row, array $payload): array
    {
        $name = $this->value($payload, 'Customer Name', 'Display Name', 'Contact Name');
        if ($name === null) {
            return ['created' => false, 'id' => null];
        }

        $sourceId = trim((string) ($row['partner_source_id'] ?? $payload['Customer ID'] ?? $payload['Contact ID'] ?? ''));
        $sourceLink = ! empty($row['source_key']) && Schema::hasTable('tan90_master_source_rows')
            ? DB::table('tan90_master_source_rows')->where('source_file', $sourceFile)->where('source_key', $row['source_key'])->first(['canonical_type', 'canonical_id'])
            : null;
        $customer = $sourceLink?->canonical_type === 'customer' && $sourceLink->canonical_id
            ? DB::table('tan90_customers')->where('id', $sourceLink->canonical_id)->first()
            : null;
        if (! $customer && $sourceId !== '' && Schema::hasTable('tan90_master_external_entities')) {
            $external = DB::table('tan90_master_external_entities')->where('partner_type', 'customer')->where('source_entity_id', $sourceId)->first(['canonical_type', 'canonical_id']);
            $customer = $external?->canonical_type === 'customer' && $external->canonical_id
                ? DB::table('tan90_customers')->where('id', $external->canonical_id)->first()
                : null;
        }

        $sourceCode = preg_replace('/[^A-Za-z0-9_-]/', '-', $sourceId);
        $code = $this->value($payload, 'Customer Number', 'Customer Code')
            ?? ($sourceId !== '' ? 'IMPORT-C-'.substr($sourceCode, 0, 40) : 'IMPORT-C-'.substr(hash('sha256', mb_strtolower(trim($name))), 0, 20));
        $customer ??= DB::table('tan90_customers')->where('code', $code)->first();
        if (! $customer) {
            $sameName = DB::table('tan90_customers')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])->limit(2)->get();
            if ($sameName->count() === 1) {
                $customer = $sameName->first();
            }
        }

        $created = $customer === null;
        $now = now();
        $status = $this->isActive($this->value($payload, 'Status')) ? 'active' : 'inactive';
        $values = array_filter([
            'code' => $code,
            'name' => $name,
            'gstin' => $this->value($payload, 'GST Identification Number (GSTIN)'),
            'segment' => $this->customerSegment($payload),
            'state' => $this->value($payload, 'Billing State', 'Shipping State'),
            'city' => $this->value($payload, 'Billing City', 'Shipping City'),
            'address_building' => $this->textValue($payload, 'Billing Address', 'Shipping Address'),
            'address_street' => $this->textValue($payload, 'Billing Street2', 'Shipping Street2'),
            'address_zip' => $this->value($payload, 'Billing Code', 'Shipping Code'),
            'credit_limit' => $this->number($this->value($payload, 'Credit Limit')),
            'payment_terms' => $this->masterPaymentTerms($this->value($payload, 'Payment Terms Label', 'Payment Terms')),
            'status' => $status,
            'updated_at' => $now,
        ], fn ($value): bool => $value !== null && $value !== '');

        if ($created) {
            $values['approval_status'] = 'draft';
            $values['version'] = 1;
            $values['created_at'] = $now;
            $id = DB::table('tan90_customers')->insertGetId($values);
        } else {
            DB::table('tan90_customers')->where('id', $customer->id)->update($values);
            $id = (int) $customer->id;
        }

        if ($sourceId !== '' && Schema::hasTable('tan90_master_external_entities')) {
            DB::table('tan90_master_external_entities')->updateOrInsert(
                ['partner_type' => 'customer', 'source_entity_id' => $sourceId],
                [
                    'source_file' => $sourceFile,
                    'display_name' => $name,
                    'canonical_type' => 'customer',
                    'canonical_id' => $id,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
            foreach (['tan90_master_addresses', 'tan90_master_contact_people'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('partner_type', 'customer')->where('partner_source_id', $sourceId)
                        ->update(['canonical_type' => 'customer', 'canonical_id' => $id, 'updated_at' => $now]);
                }
            }
        }

        return ['created' => $created, 'id' => $id];
    }

    private function writeVendor(string $sourceFile, array $row, array $payload): array
    {
        $name = $this->value($payload, 'Display Name', 'Company Name', 'Contact Name');
        if ($name === null) {
            return ['created' => false, 'id' => null];
        }

        $code = $this->value($payload, 'Vendor Number') ?? 'IMPORT-V-'.max(1, (int) ($row['source_row_number'] ?? 1));
        $sourceLink = ! empty($row['source_key']) && Schema::hasTable('tan90_master_source_rows')
            ? DB::table('tan90_master_source_rows')->where('source_file', $sourceFile)->where('source_key', $row['source_key'])->first(['canonical_type', 'canonical_id'])
            : null;
        $vendor = $sourceLink?->canonical_type === 'vendor' && $sourceLink->canonical_id
            ? DB::table('tan90_vendors')->where('id', $sourceLink->canonical_id)->first()
            : null;
        $vendor ??= DB::table('tan90_vendors')->where('code', $code)->first();
        $created = $vendor === null;
        $now = now();
        $contact = $this->value($payload, 'Contact Name')
            ?? trim(implode(' ', array_filter([$this->value($payload, 'First Name'), $this->value($payload, 'Last Name')])))
            ?: null;
        $status = $this->isActive($this->value($payload, 'Status')) ? 'active' : 'inactive';
        $values = array_filter([
            'name' => $name,
            'gstin' => $this->value($payload, 'GST Identification Number (GSTIN)'),
            'category' => $this->value($payload, 'ContactPerson.CF.Vendor Type', 'TAG.Category', 'TAG.Business Vertical'),
            'state' => $this->value($payload, 'Billing State', 'Shipping State'),
            'city' => $this->value($payload, 'Billing City', 'Shipping City'),
            'contact' => $contact,
            'phone' => $this->value($payload, 'MobilePhone', 'Phone', 'CF.Additional Number'),
            'email' => $this->value($payload, 'EmailID', 'CF.Additional Email ID'),
            'payment_terms' => $this->masterPaymentTerms($this->value($payload, 'Payment Terms Label', 'Payment Terms')),
            'status' => $status,
            'updated_at' => $now,
        ], fn ($value): bool => $value !== null && $value !== '');

        if ($created) {
            $values['code'] = $code;
            $values['name'] = $name;
            $values['status'] = $status;
            $values['approval_status'] = 'draft';
            $values['version'] = 1;
            $values['created_at'] = $now;
            $id = DB::table('tan90_vendors')->insertGetId($values);
        } else {
            DB::table('tan90_vendors')->where('id', $vendor->id)->update($values);
            $id = (int) $vendor->id;
        }

        $legacyCreated = false;
        if (Schema::hasTable('vendor_masters') && Schema::hasColumn('vendor_masters', 'vendor_number')) {
            $legacyCreated = $this->writeLegacyVendor($code, $name, $payload, $status, $now);
        }

        $sourceEntityId = trim((string) ($row['partner_source_id'] ?? ''));
        if ($sourceEntityId !== '' && Schema::hasTable('tan90_master_external_entities')) {
            DB::table('tan90_master_external_entities')
                ->where('partner_type', 'vendor')
                ->where('source_entity_id', $sourceEntityId)
                ->update(['canonical_type' => 'vendor', 'canonical_id' => $id, 'updated_at' => $now]);
            foreach (['tan90_master_addresses', 'tan90_master_contact_people'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('partner_type', 'vendor')->where('partner_source_id', $sourceEntityId)
                        ->update(['canonical_type' => 'vendor', 'canonical_id' => $id, 'updated_at' => $now]);
                }
            }
        }

        return ['created' => $created || $legacyCreated, 'id' => $id];
    }

    private function writeLegacyVendor(string $code, string $name, array $payload, string $status, $now): bool
    {
        $vendor = DB::table('vendor_masters')->where('vendor_number', $code)->first();
        if (! $vendor) {
            $sameName = DB::table('vendor_masters')
                ->whereNull('vendor_number')
                ->whereRaw('LOWER(TRIM(vendor_name)) = ?', [mb_strtolower(trim($name))])
                ->orderBy('id')
                ->limit(2)
                ->get();
            if ($sameName->count() === 1) {
                $vendor = $sameName->first();
            }
        }

        $category = $this->value($payload, 'ContactPerson.CF.Vendor Type', 'TAG.Category', 'TAG.Business Vertical') ?? 'Other';
        $values = array_filter([
            'vendor_number' => $code,
            'vendor_name' => $name,
            'gst_number' => $this->value($payload, 'GST Identification Number (GSTIN)') ?? ($vendor->gst_number ?? ''),
            'contact_phone' => $this->value($payload, 'MobilePhone', 'Phone', 'CF.Additional Number') ?? ($vendor->contact_phone ?? ''),
            'contact_email' => $this->value($payload, 'EmailID', 'CF.Additional Email ID') ?? ($vendor->contact_email ?? null),
            'category' => $category,
            'active' => $status === 'active',
            'vendor_owner' => $this->value($payload, 'Owner Name') ?? ($vendor->vendor_owner ?? null),
            'website' => $this->value($payload, 'Website') ?? ($vendor->website ?? null),
            'address_country' => $this->value($payload, 'Billing Country', 'Shipping Country') ?? ($vendor->address_country ?? null),
            'address_building' => $this->textValue($payload, 'Billing Address', 'Shipping Address') ?? ($vendor->address_building ?? null),
            'address_street' => $this->textValue($payload, 'Billing Street2', 'Shipping Street2') ?? ($vendor->address_street ?? null),
            'address_city' => $this->value($payload, 'Billing City', 'Shipping City') ?? ($vendor->address_city ?? null),
            'address_state' => $this->value($payload, 'Billing State', 'Shipping State') ?? ($vendor->address_state ?? null),
            'address_zip' => $this->value($payload, 'Billing Code', 'Shipping Code') ?? ($vendor->address_zip ?? null),
            'description' => $this->textValue($payload, 'Notes') ?? ($vendor->description ?? null),
            'gst_treatment' => $this->value($payload, 'GST Treatment') ?? ($vendor->gst_treatment ?? null),
            'source_of_supply' => $this->value($payload, 'Source of Supply') ?? ($vendor->source_of_supply ?? null),
            'msme_number' => $this->value($payload, 'MSME/Udyam No') ?? ($vendor->msme_number ?? null),
            'msme_type' => $this->value($payload, 'MSME/Udyam Type') ?? ($vendor->msme_type ?? null),
            'tds_name' => $this->value($payload, 'TDS Name') ?? ($vendor->tds_name ?? null),
            'tds_percentage' => $this->number($this->value($payload, 'TDS Percentage')) ?? ($vendor->tds_percentage ?? null),
            'tds_section_code' => $this->value($payload, 'TDS Section Code') ?? ($vendor->tds_section_code ?? null),
            'tds_section' => $this->value($payload, 'TDS Section') ?? ($vendor->tds_section ?? null),
            'updated_at' => $now,
        ], fn ($value): bool => $value !== null && $value !== '');

        if ($vendor) {
            DB::table('vendor_masters')->where('id', $vendor->id)->update($values);
        } else {
            $values['gst_number'] = $values['gst_number'] ?? '';
            $values['contact_phone'] = $values['contact_phone'] ?? '';
            $values['category'] = $values['category'] ?? 'Other';
            $values['email_opt_out'] = false;
            $values['created_at'] = $now;
            DB::table('vendor_masters')->insert($values);
        }

        return $vendor === null;
    }

    private function linkSourceRow(string $sourceFile, array $row, string $type, ?int $canonicalId): void
    {
        if ($canonicalId === null || empty($row['source_key']) || ! Schema::hasTable('tan90_master_source_rows')) {
            return;
        }

        DB::table('tan90_master_source_rows')
            ->where('source_file', $sourceFile)
            ->where('source_key', $row['source_key'])
            ->update(['canonical_type' => $type, 'canonical_id' => $canonicalId, 'updated_at' => now()]);
    }

    private function uniqueInventorySku(string $name, ?string $code): string
    {
        $candidate = $name.' ['.($code ?: 'import').']';
        $base = $candidate;
        for ($suffix = 2; DB::table('inventory_items')->where('sku', $candidate)->exists()
            || DB::table('sku_masters')->where('sku', $candidate)->exists(); $suffix++) {
            $candidate = $base.' ('.$suffix.')';
        }

        return $candidate;
    }

    private function sameItemName(string $current, string $source): bool
    {
        $current = mb_strtolower(trim($current));
        $source = mb_strtolower(trim($source));

        return $current === $source || str_starts_with($current, $source.' [');
    }

    private function itemType(array $payload): string
    {
        $tag = mb_strtolower(implode(' ', array_filter([
            $this->value($payload, 'TAG.Category'),
            $this->value($payload, 'TAG.Business Vertical'),
        ])));
        $itemType = mb_strtolower((string) $this->value($payload, 'Item Type'));

        return match (true) {
            $this->isTrue($this->value($payload, 'Is Combo Product')) => 'fg',
            str_contains($tag, 'pcm') => 'rm',
            (bool) preg_match('/box|bag|panel|sachet|packaging/', $tag) => 'pm',
            in_array($itemType, ['purchases', 'inventory'], true) => 'rm',
            in_array($itemType, ['sales', 'sales and purchases'], true) => 'trading',
            mb_strtolower((string) $this->value($payload, 'Product Type')) === 'service' => 'trading',
            default => 'rm',
        };
    }

    private function unit(?string $value): string
    {
        $unit = mb_strtolower(trim((string) $value));

        return match (true) {
            in_array($unit, ['', 'nos', 'no', 'no.', 'pcs', 'pc', 'piece', 'pieces', 'numbers', 'number', 'units', 'unit', 'qty', 'quantity'], true) => 'PCS',
            in_array($unit, ['kg', 'kgs', 'kilogram', 'kilograms'], true) => 'KG',
            in_array($unit, ['g', 'gm', 'gms', 'gram', 'grams'], true) => 'GM',
            in_array($unit, ['box', 'boxes'], true) => 'BOX',
            in_array($unit, ['l', 'ltr', 'ltrs', 'litre', 'litres', 'liter', 'liters'], true) => 'LTR',
            in_array($unit, ['m', 'mtr', 'mtrs', 'meter', 'meters', 'metre', 'metres'], true) => 'MTR',
            in_array($unit, ['ft', 'feet', 'foot'], true) => 'FT',
            in_array($unit, ['roll', 'rolls'], true) => 'ROLL',
            in_array($unit, ['set', 'sets'], true) => 'SET',
            default => 'NOS',
        };
    }

    private function masterPaymentTerms(?string $value): ?string
    {
        $terms = mb_strtolower(trim((string) $value));

        return match (true) {
            str_contains($terms, 'advance') => 'Advance',
            str_contains($terms, '15') => '15 Days',
            str_contains($terms, '30') => '30 Days',
            str_contains($terms, '45') => '45 Days',
            str_contains($terms, '60') => '60 Days',
            default => null,
        };
    }

    private function customerSegment(array $payload): string
    {
        $allowed = ['Pharma', 'Frozen Foods', 'Dairy', 'Agriculture', 'E-commerce', 'Other'];
        $segment = $this->value($payload, 'Customer Segment', 'Industry', 'TAG.Category', 'TAG.Business Vertical');

        foreach ($allowed as $option) {
            if ($segment !== null && mb_strtolower(trim($segment)) === mb_strtolower($option)) {
                return $option;
            }
        }

        return 'Other';
    }

    private function number(?string $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $number = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value));

        return is_numeric($number) ? (float) $number : null;
    }

    private function isActive(?string $value): bool
    {
        return ! in_array(mb_strtolower(trim((string) $value)), ['inactive', 'false', 'no', '0', 'archived'], true);
    }

    private function isTrue(?string $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['true', 'yes', '1', 'active'], true);
    }

    private function slug(?string $value): ?string
    {
        return $value === null ? null : str_replace(' ', '_', mb_strtolower(trim($value)));
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

    private function joinValues(array $payload, string ...$keys): ?string
    {
        $values = array_filter(array_map(fn (string $key): ?string => $this->value($payload, $key), $keys));

        return $values === [] ? null : mb_substr(implode(', ', array_unique($values)), 0, 255);
    }
}
