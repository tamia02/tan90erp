<?php

namespace App\Services\Zoho;

use App\Models\ZohoFieldDefinition;
use App\Models\ZohoLocation;
use RuntimeException;

// Copies Zoho's custom field definitions (label, type, required flag, dropdown
// options) for every Zoho module into zoho_field_definitions, so ERP forms show
// and enforce the same fields. One GET call. Fields removed or deactivated in
// Zoho are switched off here; nothing is deleted.
class ZohoFieldPullService
{
    public function __construct(private ZohoReadClient $zoho) {}

    /** @return array<string, int> */
    public function pull(): array
    {
        if (! $this->zoho->configured()) {
            throw new RuntimeException('Zoho pull is not configured (organization id / refresh token / client).');
        }

        $byEntity = $this->zoho->get('/settings/customfields')['customfields'] ?? [];
        $stats = ['entities' => 0, 'fields' => 0, 'mandatory' => 0, 'deactivated' => 0];
        $seen = [];

        foreach ($byEntity as $entity => $fields) {
            if (! is_array($fields) || $fields === []) {
                continue;
            }
            $stats['entities']++;
            foreach ($fields as $index => $field) {
                $apiName = (string) ($field['api_name'] ?? $field['placeholder'] ?? '');
                if ($apiName === '') {
                    continue;
                }
                $active = (bool) ($field['is_active'] ?? true);
                $options = collect($field['values'] ?? [])->filter(fn ($v) => $v['is_active'] ?? true)->sortBy('order')->pluck('name')->values()->all();

                ZohoFieldDefinition::updateOrCreate(['entity' => $entity, 'api_name' => $apiName], [
                    'label' => (string) ($field['label'] ?? $apiName),
                    'data_type' => (string) ($field['data_type'] ?? 'string'),
                    'is_mandatory' => (bool) ($field['is_mandatory'] ?? false),
                    'options' => $options ?: null,
                    'sort_order' => (int) ($field['index'] ?? $index),
                    'active' => $active,
                    'zoho_field_id' => isset($field['customfield_id']) ? (string) $field['customfield_id'] : null,
                ]);
                $seen[] = $entity.'|'.$apiName;
                $stats['fields']++;
                $stats['mandatory'] += ($active && ($field['is_mandatory'] ?? false)) ? 1 : 0;
            }
        }

        // Warehouses: the /locations API is not open to this key (403), so learn
        // them from the client's recent bundles - every bundle carries one.
        $bundles = $this->zoho->get('/bundles', ['per_page' => 200])['bundles'] ?? [];
        $stats['locations'] = 0;
        foreach (collect($bundles)->filter(fn ($b) => filled($b['location_id'] ?? null))->unique('location_id') as $bundle) {
            ZohoLocation::updateOrCreate(['zoho_location_id' => (string) $bundle['location_id']], ['name' => (string) ($bundle['location_name'] ?? $bundle['location_id']), 'active' => true]);
            $stats['locations']++;
        }

        foreach (ZohoFieldDefinition::where('active', true)->get() as $definition) {
            if (! in_array($definition->entity.'|'.$definition->api_name, $seen, true)) {
                $definition->update(['active' => false]);
                $stats['deactivated']++;
            }
        }

        return $stats;
    }
}
