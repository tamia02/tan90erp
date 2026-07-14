<?php

use App\Models\Tan90\BomRecipeCosting\AlternateComponent;
use App\Models\Tan90\BomRecipeCosting\Component;
use App\Models\Tan90\BomRecipeCosting\CostRate;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\Tan90\BomRecipeCosting\QualitySpec;
use App\Models\Tan90\BomRecipeCosting\SubstitutionRule;
use App\Models\Tan90\BomRecipeCosting\TemperatureProfile;
use App\Models\Tan90\BomRecipeCosting\WorkCenter;

/**
 * Tan90 BOM, Recipe & Costing — entity registry.
 *
 * Mirrors the Master Data module's config/tan90_master_data.php pattern:
 * simple reference/master entities are declared here and served by one
 * generic MasterDataController (Recipes/BOMs/Routings/Cost Sheets/ECOs are
 * deliberately NOT here — those go through dedicated controllers backed by
 * the module's services, since their write paths are governed by revision,
 * approval, and release-gate rules rather than a plain create/edit form).
 */
return [

    'nav_groups' => [
        'Product Masters' => ['finished-goods', 'components'],
        'Manufacturing Masters' => ['work-centers', 'temperature-profiles'],
        'Quality & Substitution' => ['quality-specs', 'alternate-components', 'substitution-rules'],
        'Costing Masters' => ['cost-rates'],
    ],

    'entities' => [

        'finished-goods' => [
            'model' => FinishedGood::class,
            'title' => 'Finished Goods',
            'singular' => 'Finished Good',
            'eyebrow' => 'Product Master',
            'description' => 'Sellable finished products that carry a recipe, BOM, routing and cost sheet.',
            'icon' => 'FG',
            'primary' => 'name',
            'code' => 'code',
            'columns' => ['code', 'name', 'category', 'uom', 'pack_size', 'approval_status', 'status'],
            'searchable' => ['code', 'name', 'category'],
            'critical_fields' => [],
            'fields' => [
                ['name' => 'code', 'label' => 'FG Code', 'type' => 'text', 'required' => true],
                ['name' => 'name', 'label' => 'Finished Good Name', 'type' => 'text', 'required' => true],
                ['name' => 'category', 'label' => 'Category', 'type' => 'text', 'required' => false],
                ['name' => 'uom', 'label' => 'UOM', 'type' => 'text', 'required' => false],
                ['name' => 'pack_size', 'label' => 'Pack Size', 'type' => 'text', 'required' => false],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => false],
            ],
        ],

        'components' => [
            'model' => Component::class,
            'title' => 'Component Master',
            'singular' => 'Component',
            'eyebrow' => 'Product Master',
            'description' => 'Raw materials, packaging and intermediates used across recipes and BOMs.',
            'icon' => 'CM',
            'primary' => 'name',
            'code' => 'code',
            'columns' => ['code', 'name', 'type', 'uom', 'standard_cost', 'approval_status', 'status'],
            'searchable' => ['code', 'name'],
            'critical_fields' => ['standard_cost', 'uom'],
            'fields' => [
                ['name' => 'code', 'label' => 'Component Code', 'type' => 'text', 'required' => true],
                ['name' => 'name', 'label' => 'Component Name', 'type' => 'text', 'required' => true],
                ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => ['Raw Material', 'Packaging', 'Intermediate', 'Consumable']],
                ['name' => 'uom', 'label' => 'UOM', 'type' => 'text', 'required' => false],
                ['name' => 'standard_cost', 'label' => 'Standard Cost', 'type' => 'number', 'required' => false],
            ],
        ],

        'work-centers' => [
            'model' => WorkCenter::class,
            'title' => 'Work Centers',
            'singular' => 'Work Center',
            'eyebrow' => 'Manufacturing Master',
            'description' => 'Production resources with capacity and labor/machine/overhead rates used in routings and cost roll-ups.',
            'icon' => 'WC',
            'primary' => 'name',
            'code' => 'code',
            'columns' => ['code', 'name', 'plant', 'capacity_per_hour', 'labor_rate', 'machine_rate', 'approval_status'],
            'searchable' => ['code', 'name', 'plant'],
            'critical_fields' => ['labor_rate', 'machine_rate', 'overhead_rate'],
            'fields' => [
                ['name' => 'code', 'label' => 'Work Center Code', 'type' => 'text', 'required' => true],
                ['name' => 'name', 'label' => 'Work Center Name', 'type' => 'text', 'required' => true],
                ['name' => 'plant', 'label' => 'Plant', 'type' => 'text', 'required' => false],
                ['name' => 'capacity_per_hour', 'label' => 'Capacity / Hour', 'type' => 'number', 'required' => false],
                ['name' => 'labor_rate', 'label' => 'Labor Rate', 'type' => 'number', 'required' => true],
                ['name' => 'machine_rate', 'label' => 'Machine Rate', 'type' => 'number', 'required' => true],
                ['name' => 'overhead_rate', 'label' => 'Overhead Rate', 'type' => 'number', 'required' => true],
            ],
        ],

        'temperature-profiles' => [
            'model' => TemperatureProfile::class,
            'title' => 'Temperature Profiles',
            'singular' => 'Temperature Profile',
            'eyebrow' => 'Manufacturing Master',
            'description' => 'Storage and process temperature bands referenced by recipes and quality specs.',
            'icon' => 'TP',
            'primary' => 'name',
            'code' => 'code',
            'columns' => ['code', 'name', 'min_temp', 'max_temp', 'monitoring_frequency', 'approval_status'],
            'searchable' => ['code', 'name'],
            'critical_fields' => [],
            'fields' => [
                ['name' => 'code', 'label' => 'Profile Code', 'type' => 'text', 'required' => true],
                ['name' => 'name', 'label' => 'Profile Name', 'type' => 'text', 'required' => true],
                ['name' => 'min_temp', 'label' => 'Min Temperature', 'type' => 'text', 'required' => false],
                ['name' => 'max_temp', 'label' => 'Max Temperature', 'type' => 'text', 'required' => false],
                ['name' => 'storage_condition', 'label' => 'Storage Condition', 'type' => 'text', 'required' => false],
                ['name' => 'monitoring_frequency', 'label' => 'Monitoring Frequency', 'type' => 'select', 'required' => true, 'options' => ['Continuous', 'Hourly', 'Per Shift', 'Daily']],
            ],
        ],

        'quality-specs' => [
            'model' => QualitySpec::class,
            'title' => 'Quality Specifications',
            'singular' => 'Quality Spec',
            'eyebrow' => 'Quality Master',
            'description' => 'Acceptance criteria for a finished good or component, enforced at QA release gates.',
            'icon' => 'QS',
            'primary' => 'parameter_name',
            'code' => 'code',
            'columns' => ['code', 'parameter_name', 'finishedGood.name', 'component.name', 'criticality', 'approval_status'],
            'searchable' => ['code', 'parameter_name'],
            'critical_fields' => [],
            'fields' => [
                ['name' => 'code', 'label' => 'Spec Code', 'type' => 'text', 'required' => true],
                ['name' => 'tan90_finished_good_id', 'label' => 'Finished Good', 'type' => 'select', 'required' => false, 'relation' => ['model' => FinishedGood::class, 'label_field' => 'name']],
                ['name' => 'tan90_component_id', 'label' => 'Component', 'type' => 'select', 'required' => false, 'relation' => ['model' => Component::class, 'label_field' => 'name']],
                ['name' => 'parameter_name', 'label' => 'Parameter Name', 'type' => 'text', 'required' => true],
                ['name' => 'min_value', 'label' => 'Min Value', 'type' => 'text', 'required' => false],
                ['name' => 'max_value', 'label' => 'Max Value', 'type' => 'text', 'required' => false],
                ['name' => 'uom', 'label' => 'Unit', 'type' => 'text', 'required' => false],
                ['name' => 'criticality', 'label' => 'Criticality', 'type' => 'select', 'required' => true, 'options' => ['Critical', 'Major', 'Minor']],
            ],
        ],

        'alternate-components' => [
            'model' => AlternateComponent::class,
            'title' => 'Alternate Components',
            'singular' => 'Alternate Component',
            'eyebrow' => 'Quality Master',
            'description' => 'Approved alternates for a component, valid within an effective-date window.',
            'icon' => 'AC',
            'primary' => 'id',
            'code' => 'id',
            'columns' => ['component.name', 'alternateComponent.name', 'ratio', 'effective_from', 'effective_to', 'approval_status'],
            'searchable' => [],
            'critical_fields' => ['ratio'],
            'fields' => [
                ['name' => 'tan90_component_id', 'label' => 'Component', 'type' => 'select', 'required' => true, 'relation' => ['model' => Component::class, 'label_field' => 'name']],
                ['name' => 'tan90_alternate_component_id', 'label' => 'Alternate Component', 'type' => 'select', 'required' => true, 'relation' => ['model' => Component::class, 'label_field' => 'name']],
                ['name' => 'ratio', 'label' => 'Substitution Ratio', 'type' => 'number', 'required' => true],
                ['name' => 'effective_from', 'label' => 'Effective From', 'type' => 'date', 'required' => false],
                ['name' => 'effective_to', 'label' => 'Effective To', 'type' => 'date', 'required' => false],
            ],
        ],

        'substitution-rules' => [
            'model' => SubstitutionRule::class,
            'title' => 'Substitution Rules',
            'singular' => 'Substitution Rule',
            'eyebrow' => 'Quality Master',
            'description' => 'Governs how much of a component a recipe line may substitute, and whether approval is required.',
            'icon' => 'SR',
            'primary' => 'code',
            'code' => 'code',
            'columns' => ['code', 'component.name', 'substituteComponent.name', 'max_percentage', 'requires_approval', 'approval_status'],
            'searchable' => ['code'],
            'critical_fields' => ['max_percentage'],
            'fields' => [
                ['name' => 'code', 'label' => 'Rule Code', 'type' => 'text', 'required' => true],
                ['name' => 'tan90_component_id', 'label' => 'Component', 'type' => 'select', 'required' => true, 'relation' => ['model' => Component::class, 'label_field' => 'name']],
                ['name' => 'tan90_substitute_component_id', 'label' => 'Substitute Component', 'type' => 'select', 'required' => true, 'relation' => ['model' => Component::class, 'label_field' => 'name']],
                ['name' => 'max_percentage', 'label' => 'Max Substitution %', 'type' => 'number', 'required' => false],
                ['name' => 'requires_approval', 'label' => 'Requires Approval', 'type' => 'select', 'required' => true, 'options' => ['Yes', 'No']],
            ],
        ],

        'cost-rates' => [
            'model' => CostRate::class,
            'title' => 'Cost Rates',
            'singular' => 'Cost Rate',
            'eyebrow' => 'Costing Master',
            'description' => 'Effective-dated material/labor/machine/utility/overhead rates used by cost roll-ups.',
            'icon' => 'CR',
            'primary' => 'rate_name',
            'code' => 'code',
            'columns' => ['code', 'rate_type', 'rate_name', 'rate', 'effective_from', 'effective_to', 'approval_status'],
            'searchable' => ['code', 'rate_name'],
            'critical_fields' => ['rate'],
            'fields' => [
                ['name' => 'code', 'label' => 'Rate Code', 'type' => 'text', 'required' => true],
                ['name' => 'rate_type', 'label' => 'Rate Type', 'type' => 'select', 'required' => true, 'options' => ['material', 'labor', 'machine', 'utility', 'overhead']],
                ['name' => 'rate_name', 'label' => 'Rate Name', 'type' => 'text', 'required' => true],
                ['name' => 'rate', 'label' => 'Rate', 'type' => 'number', 'required' => true],
                ['name' => 'uom', 'label' => 'Unit', 'type' => 'text', 'required' => false],
                ['name' => 'effective_from', 'label' => 'Effective From', 'type' => 'date', 'required' => false],
                ['name' => 'effective_to', 'label' => 'Effective To', 'type' => 'date', 'required' => false],
            ],
        ],

    ],

];
