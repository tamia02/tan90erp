<?php

namespace App\Support;

use App\Models\CustomFieldDefinition;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Fields the team adds to any table themselves: pick the table, name the
// field, choose the data type. Master Data records keep the values in
// custom_field_values; Inventory Items keep theirs on inventory_items.
class CustomFields
{
    public const TYPES = [
        'text' => 'Text',
        'long_text' => 'Long text',
        'number' => 'Number',
        'date' => 'Date',
        'dropdown' => 'Dropdown (choose from a list)',
        'yes_no' => 'Yes / No',
    ];

    public const PERMISSION = 'custom_fields.manage';

    public const INVENTORY_ITEMS = 'inventory_items';

    /** Every table a field can be added to: entity key => label. */
    public static function tables(): array
    {
        $tables = [self::INVENTORY_ITEMS => 'Inventory Items'];
        foreach (config('tan90_master_data.entities', []) as $slug => $config) {
            if ($slug !== 'items') { // the one item list is Inventory Items
                $tables[self::masterEntity($slug)] = 'Master Data: '.($config['title'] ?? $slug);
            }
        }

        return $tables;
    }

    public static function masterEntity(string $slug): string
    {
        return 'master:'.$slug;
    }

    public static function canManage(?User $user): bool
    {
        return GrantedPermission::has($user, self::PERMISSION);
    }

    /** @return Collection<int, CustomFieldDefinition> */
    public static function fields(string $entity, bool $activeOnly = true): Collection
    {
        return CustomFieldDefinition::where('entity', $entity)
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Validation rules for the values, keyed "{prefix}.{field_key}". */
    public static function rules(Collection $fields, string $prefix): array
    {
        $rules = [];
        foreach ($fields as $field) {
            $rules["{$prefix}.{$field->field_key}"] = array_values(array_filter([
                $field->required ? 'required' : 'nullable',
                match ($field->input_type) {
                    'number' => 'numeric',
                    'date' => 'date',
                    'yes_no' => Rule::in(['1', '0', 1, 0, true, false]),
                    'dropdown' => Rule::in($field->options ?? []),
                    'long_text' => 'max:5000',
                    default => 'max:255',
                },
                in_array($field->input_type, ['text', 'long_text', 'dropdown'], true) ? 'string' : null,
            ]));
        }

        return $rules;
    }

    public static function attributeNames(Collection $fields, string $prefix): array
    {
        return $fields->mapWithKeys(fn ($f) => ["{$prefix}.{$f->field_key}" => $f->label])->all();
    }

    /** Typed values to store; blanks dropped. */
    public static function clean(Collection $fields, array $input): array
    {
        $values = [];
        foreach ($fields as $field) {
            $raw = $input[$field->field_key] ?? null;
            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }
            $values[$field->field_key] = match ($field->input_type) {
                'number' => (float) $raw,
                'yes_no' => in_array($raw, ['1', 1, true, 'true'], true),
                default => trim((string) $raw),
            };
        }

        return $values;
    }

    public static function values(string $entity, int $recordId): array
    {
        $row = DB::table('custom_field_values')->where('entity', $entity)->where('record_id', $recordId)->value('data');

        return $row ? (json_decode($row, true) ?: []) : [];
    }

    // Saves only this entity's active fields; values of turned-off fields stay.
    public static function save(string $entity, int $recordId, array $values): void
    {
        $keep = array_diff_key(self::values($entity, $recordId), self::fields($entity)->keyBy('field_key')->all());
        $data = array_merge($keep, $values);
        DB::table('custom_field_values')->updateOrInsert(
            ['entity' => $entity, 'record_id' => $recordId],
            ['data' => json_encode($data), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public static function display(\Illuminate\Database\Eloquent\Model $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($field->input_type) {
            'yes_no' => $value ? 'Yes' : 'No',
            'number' => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.'),
            'date' => \Illuminate\Support\Carbon::parse($value)->format('d M Y'),
            default => (string) $value,
        };
    }

    /**
     * Records of a table for the CSV: id + the columns people recognise them by.
     * Inventory Items never expose the masked SKU Name - only Code + Display Name.
     *
     * @return array{columns: array<string, string>, rows: \Illuminate\Support\Collection}
     */
    public static function recordsForCsv(string $entity): array
    {
        if ($entity === self::INVENTORY_ITEMS) {
            $codes = DB::table('sku_masters')->whereNotNull('product_code')->pluck('product_code', 'sku')->mapWithKeys(fn ($c, $sku) => [mb_strtolower($sku) => $c]);
            $rows = DB::table('inventory_items')->orderBy('id')->get(['id', 'sku', 'code', 'name', 'custom_fields'])
                ->map(fn ($i) => ['id' => $i->id, 'code' => $i->code ?: ($codes[mb_strtolower($i->sku)] ?? ''), 'name' => $i->name ?: (\App\Models\Inventory\InventoryItem::canSeeSkuName(auth()->user()) ? $i->sku : ''),
                    'values' => json_decode((string) $i->custom_fields, true) ?: []]);

            return ['columns' => ['code' => 'Code', 'name' => 'Display Name'], 'rows' => $rows];
        }

        $slug = substr($entity, 7);
        $config = config("tan90_master_data.entities.{$slug}");
        $model = new $config['model'];
        $table = $model->getTable();
        $cols = array_values(array_filter(['code', 'name'], fn ($c) => \Illuminate\Support\Facades\Schema::hasColumn($table, $c)));
        $values = DB::table('custom_field_values')->where('entity', $entity)->pluck('data', 'record_id');
        $rows = DB::table($table)->when(\Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('id')->get(array_merge(['id'], $cols))
            ->map(fn ($r) => ['id' => $r->id] + collect($cols)->mapWithKeys(fn ($c) => [$c => $r->{$c}])->all()
                + ['values' => json_decode((string) ($values[$r->id] ?? ''), true) ?: []]);

        return ['columns' => collect($cols)->mapWithKeys(fn ($c) => [$c => ucfirst($c)])->all(), 'rows' => $rows];
    }

    /** Writes values for one record, keeping fields not in $values. */
    public static function saveFor(string $entity, int $recordId, array $values): void
    {
        if ($entity === self::INVENTORY_ITEMS) {
            $item = \App\Models\Inventory\InventoryItem::findOrFail($recordId);
            $item->update(['custom_fields' => array_merge($item->custom_fields ?? [], $values) ?: null]);

            return;
        }
        DB::table('custom_field_values')->updateOrInsert(
            ['entity' => $entity, 'record_id' => $recordId],
            ['data' => json_encode(array_merge(self::values($entity, $recordId), $values)), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    // New field: unique key per table from its name.
    public static function add(string $entity, string $label, string $type, ?string $options, bool $required, ?User $by): CustomFieldDefinition
    {
        $key = str($label)->slug('_')->limit(50, '')->toString() ?: 'field';
        $base = $key;
        for ($n = 2; CustomFieldDefinition::where('entity', $entity)->where('field_key', $key)->exists(); $n++) {
            $key = "{$base}_{$n}";
        }

        return CustomFieldDefinition::create([
            'entity' => $entity, 'field_key' => $key, 'label' => trim($label), 'input_type' => $type,
            'options' => $type === 'dropdown' ? array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $options))))) : null,
            'required' => $required, 'sort_order' => (int) CustomFieldDefinition::where('entity', $entity)->max('sort_order') + 1,
            'created_by' => $by?->id,
        ]);
    }
}
