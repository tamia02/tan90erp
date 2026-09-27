<?php

namespace Database\Seeders;

use App\Models\Tan90\MasterData\ApprovalWorkflow;
use App\Models\Tan90\MasterData\ApprovalWorkflowStep;
use App\Models\Tan90\MasterData\Bin;
use App\Models\Tan90\MasterData\BusinessUnit;
use App\Models\Tan90\MasterData\Customer;
use App\Models\Tan90\MasterData\DataQualityRule;
use App\Models\Tan90\MasterData\DocumentRule;
use App\Models\Tan90\MasterData\HsnTaxRule;
use App\Models\Tan90\MasterData\IntegrationConnection;
use App\Models\Tan90\MasterData\Item;
use App\Models\Tan90\MasterData\ItemCategory;
use App\Models\Tan90\MasterData\LegalEntity;
use App\Models\Tan90\MasterData\Location;
use App\Models\Tan90\MasterData\LocationGstRegistration;
use App\Models\Tan90\MasterData\Machine;
use App\Models\Tan90\MasterData\ModuleSetting;
use App\Models\Tan90\MasterData\NotificationTemplate;
use App\Models\Tan90\MasterData\NumberSeries;
use App\Models\Tan90\MasterData\Permission;
use App\Models\Tan90\MasterData\Plant;
use App\Models\Tan90\MasterData\QualityParameter;
use App\Models\Tan90\MasterData\Rack;
use App\Models\Tan90\MasterData\Role;
use App\Models\Tan90\MasterData\Shelf;
use App\Models\Tan90\MasterData\SlaPolicy;
use App\Models\Tan90\MasterData\TemperatureClass;
use App\Models\Tan90\MasterData\Transporter;
use App\Models\Tan90\MasterData\Uom;
use App\Models\Tan90\MasterData\UserProfile;
use App\Models\Tan90\MasterData\Vendor;
use App\Models\Tan90\MasterData\VendorContact;
use App\Models\Tan90\MasterData\Warehouse;
use App\Models\Tan90\MasterData\WarehouseZone;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Ports the Phase 1 subset of assets/js/seed-data.js into real rows. Record
 * ids/order intentionally mirror the demo's seed data so QA can cross-check
 * behavior between the two side by side.
 *
 * Run: php artisan db:seed --class=Database\\Seeders\\Tan90MasterDataSeeder
 */
class Tan90MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissionsAndRoles();
        $users = $this->seedUsers();
        $this->seedModuleSettings();

        $legalEntities = $this->seedLegalEntities();
        $businessUnits = $this->seedBusinessUnits($legalEntities);
        $locations = $this->seedLocations();
        $this->seedLocationGstRegistrations($locations);
        $plants = $this->seedPlants($businessUnits, $locations);
        $this->seedWarehouses($plants, $locations);
        $this->seedUserProfiles($users, $plants, $locations);

        $categories = $this->seedItemCategories();
        $uoms = $this->seedUoms();
        $this->seedItems($categories, $uoms);

        $vendors = $this->seedVendors();
        $this->seedVendorContacts($vendors);

        $this->seedHsnTaxRules();
        $this->seedTemperatureClasses();
        $this->seedQualityParameters($categories);
        $this->seedCustomers();
        $this->seedTransporters();
        $this->seedMachines($plants);
        $this->seedWarehouseHierarchy(Warehouse::all()->keyBy('code'));
        $workflows = $this->seedApprovalWorkflows();
        $this->seedApprovalWorkflowSteps($workflows);
        $this->seedNumberSeries();
        $this->seedSlaPolicies();
        $this->seedDocumentRules();
        $this->seedNotificationTemplates();
        $this->seedIntegrationConnections();
        $this->seedDataQualityRules();

        $this->command?->info('Tan90 Master Data seed complete (Phase 1 + Phase 2 reference data).');
    }

    private function seedPermissionsAndRoles(): void
    {
        foreach (Permission::CATALOG as $key => $label) {
            Permission::updateOrCreate(['key' => $key], ['label' => $label]);
        }

        $roles = [
            ['code' => 'ROLE-SUPER', 'name' => 'Super Admin', 'type' => 'System', 'data_scope' => 'All Entities',
                'grants' => ['view', 'create', 'edit', 'delete', 'approve', 'export', 'settings']],
            ['code' => 'ROLE-MDM', 'name' => 'Master Data Manager', 'type' => 'Operational', 'data_scope' => 'All Entities',
                'grants' => ['view', 'create', 'edit', 'approve', 'export']],
            ['code' => 'ROLE-PLANT', 'name' => 'Plant User', 'type' => 'Operational', 'data_scope' => 'Assigned Plant',
                'grants' => ['view', 'export']],
            ['code' => 'ROLE-AUDIT', 'name' => 'Auditor', 'type' => 'Read Only', 'data_scope' => 'All Entities',
                'grants' => ['view', 'export']],
            // Referenced by seeded tan90_approval_workflow_steps (WF-ITEM-CREATE,
            // WF-VENDOR-ONBOARD) and tan90_sla_policies.escalation_role, so the
            // multi-step workflow demo is actually completable end to end.
            ['code' => 'ROLE-QC', 'name' => 'QC', 'type' => 'Operational', 'data_scope' => 'All Entities',
                'grants' => ['view', 'approve']],
            ['code' => 'ROLE-FINANCE', 'name' => 'Finance', 'type' => 'Operational', 'data_scope' => 'All Entities',
                'grants' => ['view', 'approve']],
            ['code' => 'ROLE-PROCUREMENT', 'name' => 'Procurement', 'type' => 'Operational', 'data_scope' => 'All Entities',
                'grants' => ['view', 'approve']],
        ];

        foreach ($roles as $definition) {
            $role = Role::updateOrCreate(
                ['code' => $definition['code']],
                ['name' => $definition['name'], 'type' => $definition['type'], 'data_scope' => $definition['data_scope'], 'status' => 'active']
            );

            $sync = [];
            foreach (Permission::all() as $permission) {
                $sync[$permission->id] = ['allowed' => in_array($permission->key, $definition['grants'], true)];
            }
            $role->permissions()->sync($sync);
        }
    }

    /** @return array<string, User> keyed by email */
    private function seedUsers(): array
    {
        $accounts = [
            ['email' => 'admin@tan90.demo', 'name' => 'Super Admin'],
            ['email' => 'masterdata@tan90.demo', 'name' => 'Master Data Manager'],
            ['email' => 'plant@tan90.demo', 'name' => 'Chennai Plant User'],
            ['email' => 'auditor@tan90.demo', 'name' => 'Audit User'],
        ];

        $users = [];
        foreach ($accounts as $account) {
            $users[$account['email']] = User::firstOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'password' => Hash::make('demo123'), 'email_verified_at' => now()]
            );
        }

        return $users;
    }

    private function seedUserProfiles(array $users, array $plants, array $locations): void
    {
        $roleByCode = Role::all()->keyBy('code');

        UserProfile::updateOrCreate(['user_id' => $users['admin@tan90.demo']->id], [
            'tan90_role_id' => $roleByCode['ROLE-SUPER']->id, 'employee_id' => 'T90-0001',
            'department' => 'IT/Admin', 'all_locations' => true, 'mfa_status' => 'enabled', 'status' => 'active',
        ]);
        UserProfile::updateOrCreate(['user_id' => $users['masterdata@tan90.demo']->id], [
            'tan90_role_id' => $roleByCode['ROLE-MDM']->id, 'employee_id' => 'T90-0101',
            'department' => 'Operations', 'all_locations' => true, 'mfa_status' => 'enabled', 'status' => 'active',
        ]);
        UserProfile::updateOrCreate(['user_id' => $users['plant@tan90.demo']->id], [
            'tan90_role_id' => $roleByCode['ROLE-PLANT']->id, 'employee_id' => 'T90-0201',
            'department' => 'Store', 'assigned_plant_id' => $plants['CHENNAI-TAN90-MU']->id,
            'assigned_location_id' => $locations['LOC-CHN']->id, 'mfa_status' => 'pending', 'status' => 'active',
        ]);
        UserProfile::updateOrCreate(['user_id' => $users['auditor@tan90.demo']->id], [
            'tan90_role_id' => $roleByCode['ROLE-AUDIT']->id, 'employee_id' => 'T90-0999',
            'department' => 'Audit', 'all_locations' => true, 'mfa_status' => 'enabled', 'status' => 'active',
        ]);
    }

    private function seedModuleSettings(): void
    {
        $defaults = [
            'general' => ['companyName' => 'Tan90 Thermal Solutions', 'dateFormat' => 'DD MMM YYYY', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'language' => 'English'],
            'masterPolicy' => ['approvalRequired' => '1', 'softDelete' => '1', 'makerChecker' => '1', 'effectiveDating' => '1', 'duplicateThreshold' => '85'],
            'gst' => ['enabled' => '0', 'endpointTemplate' => 'https://sheet.gstincheck.co.in/check/{api_key}/{gstin}', 'timeout' => '12', 'cacheHours' => '24'],
            'maps' => ['enabled' => '0', 'autocomplete' => '1'],
            'email' => ['mailer' => 'log', 'port' => '465', 'encryption' => 'ssl', 'fromName' => 'Tan90 ERP'],
            'security' => ['sessionTimeout' => '30', 'mfaRequired' => '1', 'passwordDays' => '90', 'auditRetention' => '8 Years'],
        ];

        $secretKeys = ['apiKey', 'browserKey', 'serverKey', 'password'];

        foreach ($defaults as $group => $values) {
            foreach ($values as $key => $value) {
                ModuleSetting::firstOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => $value, 'is_encrypted' => in_array($key, $secretKeys, true)]
                );
            }
        }
    }

    /** @return array<string, LegalEntity> keyed by code */
    private function seedLegalEntities(): array
    {
        $rows = [
            ['code' => 'T90-TSPL', 'name' => 'Tan90 Thermal Solutions Private Limited', 'cin' => 'U31909TN2019PTC128118', 'gstin' => '33AAGCT9099K1ZP', 'pan' => 'AAGCT9099K', 'state' => 'Tamil Nadu', 'approval_status' => 'approved'],
            ['code' => 'T90-NORTH', 'name' => 'Tan90 North Operations', 'gstin' => '06AAGCT9099K1ZT', 'pan' => 'AAGCT9099K', 'state' => 'Haryana', 'approval_status' => 'review'],
        ];

        return collect($rows)->mapWithKeys(fn ($row) => [$row['code'] => LegalEntity::updateOrCreate(['code' => $row['code']], $row)])->all();
    }

    /** @return array<string, BusinessUnit> keyed by code */
    private function seedBusinessUnits(array $legalEntities): array
    {
        $rows = [
            ['code' => 'BU-MFG', 'name' => 'Manufacturing', 'tan90_legal_entity_id' => $legalEntities['T90-TSPL']->id, 'head' => 'Shiv Sharma', 'cost_center' => 'CC-100', 'approval_status' => 'approved'],
            ['code' => 'BU-OPS', 'name' => 'Operations & Warehousing', 'tan90_legal_entity_id' => $legalEntities['T90-TSPL']->id, 'head' => 'Akhtar Mohammad', 'cost_center' => 'CC-200', 'approval_status' => 'approved'],
            ['code' => 'BU-RND', 'name' => 'Research & Development', 'tan90_legal_entity_id' => $legalEntities['T90-TSPL']->id, 'head' => 'R&D Head', 'cost_center' => 'CC-300', 'approval_status' => 'draft'],
        ];

        return collect($rows)->mapWithKeys(fn ($row) => [$row['code'] => BusinessUnit::updateOrCreate(['code' => $row['code']], $row)])->all();
    }

    /** @return array<string, Location> keyed by code */
    private function seedLocations(): array
    {
        $rows = [
            ['code' => 'LOC-CHN', 'name' => 'Chennai', 'type' => 'Manufacturing', 'state' => 'Tamil Nadu', 'city' => 'Chennai', 'pincode' => '600077', 'gstin' => '33AAGCT9099K1ZP', 'gst_status' => 'verified', 'approval_status' => 'approved'],
            ['code' => 'LOC-DEL', 'name' => 'Delhi / Pali', 'type' => 'Manufacturing', 'state' => 'Haryana', 'city' => 'Faridabad', 'pincode' => '121004', 'gstin' => '06AAGCT9099K1ZT', 'gst_status' => 'pending', 'approval_status' => 'review'],
            ['code' => 'LOC-BHW', 'name' => 'Bhiwandi', 'type' => 'Warehouse', 'state' => 'Maharashtra', 'city' => 'Bhiwandi', 'pincode' => '421302', 'gstin' => '27AAGCT9099K1ZV', 'gst_status' => 'pending', 'approval_status' => 'draft'],
        ];

        return collect($rows)->mapWithKeys(fn ($row) => [$row['code'] => Location::updateOrCreate(['code' => $row['code']], $row)])->all();
    }

    private function seedLocationGstRegistrations(array $locations): void
    {
        LocationGstRegistration::updateOrCreate(['code' => 'GST-CHN-01'], [
            'tan90_location_id' => $locations['LOC-CHN']->id, 'gstin' => '33AAGCT9099K1ZP',
            'legal_name' => 'Tan90 Thermal Solutions Private Limited', 'gst_status' => 'verified',
            'verified_at' => now(), 'approval_status' => 'approved',
        ]);
    }

    /** @return array<string, Plant> keyed by code */
    private function seedPlants(array $businessUnits, array $locations): array
    {
        $rows = [
            ['code' => 'CHENNAI-TAN90-MU', 'name' => 'Chennai Manufacturing Unit', 'tan90_business_unit_id' => $businessUnits['BU-MFG']->id, 'tan90_location_id' => $locations['LOC-CHN']->id, 'plant_type' => 'Manufacturing Unit', 'manager' => 'Karthick', 'phone' => '+91 89258 00797', 'capacity' => '25000 units/day', 'shift_model' => '3 Shift', 'approval_status' => 'approved'],
            ['code' => 'DELHI-TAN90-MU', 'name' => 'Delhi Manufacturing Unit', 'tan90_business_unit_id' => $businessUnits['BU-MFG']->id, 'tan90_location_id' => $locations['LOC-DEL']->id, 'plant_type' => 'Manufacturing Unit', 'manager' => 'Ram Prakash', 'phone' => '+91 92056 54865', 'capacity' => '18000 units/day', 'shift_model' => '2 Shift', 'approval_status' => 'approved'],
            ['code' => 'MUMBAI-TAN90-MU', 'name' => 'Bhiwandi Warehouse Unit', 'tan90_business_unit_id' => $businessUnits['BU-OPS']->id, 'tan90_location_id' => $locations['LOC-BHW']->id, 'plant_type' => 'Warehouse', 'manager' => 'Shamim', 'phone' => '+91 89258 46275', 'capacity' => '4500 pallet positions', 'shift_model' => 'General', 'approval_status' => 'review'],
        ];

        return collect($rows)->mapWithKeys(fn ($row) => [$row['code'] => Plant::updateOrCreate(['code' => $row['code']], $row)])->all();
    }

    private function seedWarehouses(array $plants, array $locations): void
    {
        $rows = [
            ['code' => 'WH-CHN-RM', 'name' => 'Chennai Raw Material Warehouse', 'tan90_plant_id' => $plants['CHENNAI-TAN90-MU']->id, 'tan90_location_id' => $locations['LOC-CHN']->id, 'warehouse_type' => 'Raw Material', 'manager' => 'Store Manager Chennai', 'capacity' => '1200 pallet', 'temperature_zone' => 'Ambient', 'bin_tracking' => 'enabled', 'approval_status' => 'approved'],
            ['code' => 'WH-CHN-FG', 'name' => 'Chennai Finished Goods Warehouse', 'tan90_plant_id' => $plants['CHENNAI-TAN90-MU']->id, 'tan90_location_id' => $locations['LOC-CHN']->id, 'warehouse_type' => 'Finished Goods', 'manager' => 'FG Manager Chennai', 'capacity' => '1800 pallet', 'temperature_zone' => 'Controlled', 'bin_tracking' => 'enabled', 'approval_status' => 'approved'],
            ['code' => 'WH-BHW-CENTRAL', 'name' => 'Bhiwandi Central Warehouse', 'tan90_plant_id' => $plants['MUMBAI-TAN90-MU']->id, 'tan90_location_id' => $locations['LOC-BHW']->id, 'warehouse_type' => 'Central Distribution', 'manager' => 'Shamim', 'capacity' => '4500 pallet', 'temperature_zone' => 'Multi Zone', 'bin_tracking' => 'enabled', 'approval_status' => 'approved'],
            ['code' => 'WH-DEL-RM', 'name' => 'Delhi Raw Material Store', 'tan90_plant_id' => $plants['DELHI-TAN90-MU']->id, 'tan90_location_id' => $locations['LOC-DEL']->id, 'warehouse_type' => 'Raw Material', 'manager' => 'Store Manager Delhi', 'capacity' => '900 pallet', 'temperature_zone' => 'Ambient', 'bin_tracking' => 'enabled', 'approval_status' => 'review'],
        ];

        foreach ($rows as $row) {
            Warehouse::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    /** @return array<string, ItemCategory> keyed by code */
    private function seedItemCategories(): array
    {
        $rows = [
            ['code' => 'CAT-CHEM', 'name' => 'Chemicals', 'parent' => 'Raw Materials', 'valuation_method' => 'Weighted Average', 'qc_required' => 'Yes', 'batch_tracking' => 'Yes', 'approval_status' => 'approved'],
            ['code' => 'CAT-PANEL', 'name' => 'Packaging Materials - Panels', 'parent' => 'Packaging Materials', 'valuation_method' => 'FIFO', 'qc_required' => 'Yes', 'batch_tracking' => 'Optional', 'approval_status' => 'approved'],
            ['code' => 'CAT-POUCH', 'name' => 'Packaging Materials - Pouch & Roll', 'parent' => 'Packaging Materials', 'valuation_method' => 'FIFO', 'qc_required' => 'Yes', 'batch_tracking' => 'Optional', 'approval_status' => 'approved'],
            ['code' => 'CAT-FG', 'name' => 'Finished Goods', 'parent' => 'Products', 'valuation_method' => 'FIFO', 'qc_required' => 'Yes', 'batch_tracking' => 'Yes', 'approval_status' => 'review'],
        ];

        return collect($rows)->mapWithKeys(fn ($row) => [$row['code'] => ItemCategory::updateOrCreate(['code' => $row['code']], $row)])->all();
    }

    /** @return array<string, Uom> keyed by code */
    private function seedUoms(): array
    {
        $rows = [
            ['code' => 'KG', 'name' => 'Kilogram', 'type' => 'Weight', 'base_uom' => 'KG', 'conversion_factor' => 1, 'decimal_places' => 3, 'approval_status' => 'approved'],
            ['code' => 'GM', 'name' => 'Gram', 'type' => 'Weight', 'base_uom' => 'KG', 'conversion_factor' => 0.001, 'decimal_places' => 3, 'approval_status' => 'approved'],
            ['code' => 'NOS', 'name' => 'Numbers', 'type' => 'Count', 'base_uom' => 'NOS', 'conversion_factor' => 1, 'decimal_places' => 0, 'approval_status' => 'approved'],
            ['code' => 'SHEET', 'name' => 'Sheet', 'type' => 'Count', 'base_uom' => 'SHEET', 'conversion_factor' => 1, 'decimal_places' => 0, 'approval_status' => 'approved'],
            ['code' => 'L', 'name' => 'Litre', 'type' => 'Volume', 'base_uom' => 'L', 'conversion_factor' => 1, 'decimal_places' => 3, 'approval_status' => 'approved'],
        ];

        return collect($rows)->mapWithKeys(fn ($row) => [$row['code'] => Uom::updateOrCreate(['code' => $row['code']], $row)])->all();
    }

    private function seedItems(array $categories, array $uoms): void
    {
        $rows = [
            ['sku' => 'TNRMSF', 'code' => 'TNRM01', 'name' => 'Sodium Fluoride', 'category' => 'CAT-CHEM', 'uom' => 'KG', 'hsn' => '28261990', 'masking_code' => 'SFL', 'qc_required' => 'Yes', 'batch_tracking' => 'Yes', 'reorder_level' => '250 KG', 'standard_cost' => 148.00, 'approval_status' => 'approved'],
            ['sku' => 'TNRMMGN', 'code' => 'TNRM02', 'name' => 'Magnesium Nitrate', 'category' => 'CAT-CHEM', 'uom' => 'KG', 'hsn' => '28342990', 'masking_code' => 'MGN', 'qc_required' => 'Yes', 'batch_tracking' => 'Yes', 'reorder_level' => '300 KG', 'standard_cost' => 96.00, 'approval_status' => 'approved'],
            ['sku' => 'TNRMKCL', 'code' => 'TNRM03', 'name' => 'Potassium Chloride', 'category' => 'CAT-CHEM', 'uom' => 'KG', 'hsn' => '31042000', 'masking_code' => 'KCL', 'qc_required' => 'Yes', 'batch_tracking' => 'Yes', 'reorder_level' => '350 KG', 'standard_cost' => 42.00, 'approval_status' => 'approved'],
            ['sku' => 'SP-2249', 'code' => 'TNPKP01', 'name' => '500gm Blue Panel', 'category' => 'CAT-PANEL', 'uom' => 'NOS', 'hsn' => '39269099', 'masking_code' => 'BP500', 'qc_required' => 'Yes', 'batch_tracking' => 'Optional', 'reorder_level' => '1500 NOS', 'standard_cost' => 22.50, 'approval_status' => 'approved'],
            ['sku' => 'SP-1200-PINK', 'code' => 'TNPKP02', 'name' => '1200ml Pink Panel', 'category' => 'CAT-PANEL', 'uom' => 'NOS', 'hsn' => '39269099', 'masking_code' => 'PP1200', 'qc_required' => 'Yes', 'batch_tracking' => 'Optional', 'reorder_level' => '1000 NOS', 'standard_cost' => 35.00, 'approval_status' => 'review'],
            ['sku' => 'AB-10MM', 'code' => 'TNPKA01', 'name' => 'Angel Board 10mm', 'category' => 'CAT-PANEL', 'uom' => 'SHEET', 'hsn' => '39219099', 'masking_code' => 'AB10', 'qc_required' => 'Yes', 'batch_tracking' => 'No', 'reorder_level' => '300 SHEET', 'standard_cost' => 118.00, 'approval_status' => 'approved'],
        ];

        foreach ($rows as $row) {
            $categoryCode = $row['category'];
            $uomCode = $row['uom'];
            unset($row['category'], $row['uom']);
            $row['tan90_item_category_id'] = $categories[$categoryCode]->id;
            $row['tan90_uom_id'] = $uoms[$uomCode]->id;

            Item::updateOrCreate(['sku' => $row['sku']], $row);
        }
    }

    /** @return array<string, Vendor> keyed by code */
    private function seedVendors(): array
    {
        $rows = [
            ['code' => 'VEN-HCC', 'name' => 'Hindustan Chemical Corporation', 'gstin' => '27AAAFH1234K1Z9', 'gst_status' => 'verified', 'category' => 'Chemicals', 'state' => 'Maharashtra', 'city' => 'Mumbai', 'contact' => 'Rajesh Mehta', 'phone' => '+91 98765 11111', 'email' => 'accounts@hindustanchem.demo', 'payment_terms' => '30 Days', 'rating' => 4.6, 'portal_enabled' => 'Yes', 'approval_status' => 'approved'],
            ['code' => 'VEN-SHP', 'name' => 'Sharp Polymers', 'gstin' => '33AAEFS4455A1ZX', 'gst_status' => 'verified', 'category' => 'Packaging Panels', 'state' => 'Tamil Nadu', 'city' => 'Chennai', 'contact' => 'Suresh N', 'phone' => '+91 98765 22222', 'email' => 'sales@sharppolymers.demo', 'payment_terms' => '45 Days', 'rating' => 4.3, 'portal_enabled' => 'Yes', 'approval_status' => 'approved'],
            ['code' => 'VEN-KK', 'name' => 'Kemical Konnect', 'gstin' => '06AAHFK7788B1ZC', 'gst_status' => 'pending', 'category' => 'Chemicals', 'state' => 'Haryana', 'city' => 'Faridabad', 'contact' => 'Mohit Arora', 'phone' => '+91 98765 33333', 'email' => 'orders@kemicalkonnect.demo', 'payment_terms' => '30 Days', 'rating' => 4.1, 'portal_enabled' => 'No', 'approval_status' => 'review'],
            ['code' => 'VEN-VP', 'name' => 'Vraj Plastics', 'gstin' => '24AAEFV1122C1ZP', 'gst_status' => 'failed', 'category' => 'Packaging Panels', 'state' => 'Gujarat', 'city' => 'Ahmedabad', 'contact' => 'Ketan Patel', 'phone' => '+91 98765 44444', 'email' => 'sales@vrajplastics.demo', 'payment_terms' => 'Advance', 'rating' => 3.8, 'portal_enabled' => 'Yes', 'approval_status' => 'draft'],
        ];

        return collect($rows)->mapWithKeys(fn ($row) => [$row['code'] => Vendor::updateOrCreate(['code' => $row['code']], $row)])->all();
    }

    private function seedVendorContacts(array $vendors): void
    {
        $rows = [
            ['vendor' => 'VEN-HCC', 'name' => 'Rajesh Mehta', 'designation' => 'Sales Manager', 'email' => 'rajesh@hindustanchem.demo', 'phone' => '+91 98765 11111', 'is_primary' => 'Yes', 'notification_role' => 'Orders, GRN Issues', 'approval_status' => 'approved'],
            ['vendor' => 'VEN-HCC', 'name' => 'Nitin Shah', 'designation' => 'Accounts', 'email' => 'accounts@hindustanchem.demo', 'phone' => '+91 98765 11112', 'is_primary' => 'No', 'notification_role' => 'Payments, Debit Notes', 'approval_status' => 'approved'],
            ['vendor' => 'VEN-SHP', 'name' => 'Suresh N', 'designation' => 'Owner', 'email' => 'suresh@sharppolymers.demo', 'phone' => '+91 98765 22222', 'is_primary' => 'Yes', 'notification_role' => 'All', 'approval_status' => 'approved'],
        ];

        foreach ($rows as $row) {
            $vendorCode = $row['vendor'];
            unset($row['vendor']);
            $row['tan90_vendor_id'] = $vendors[$vendorCode]->id;

            VendorContact::updateOrCreate(['tan90_vendor_id' => $row['tan90_vendor_id'], 'email' => $row['email']], $row);
        }
    }

    private function seedHsnTaxRules(): void
    {
        $rows = [
            ['hsn' => '28261990', 'description' => 'Other fluorides', 'gst_rate' => '18%', 'cess' => '0%', 'input_credit' => 'Allowed', 'effective_from' => '2025-04-01', 'approval_status' => 'approved'],
            ['hsn' => '28342990', 'description' => 'Other nitrates', 'gst_rate' => '18%', 'cess' => '0%', 'input_credit' => 'Allowed', 'effective_from' => '2025-04-01', 'approval_status' => 'approved'],
            ['hsn' => '31042000', 'description' => 'Potassium chloride', 'gst_rate' => '5%', 'cess' => '0%', 'input_credit' => 'Allowed', 'effective_from' => '2025-04-01', 'approval_status' => 'approved'],
            ['hsn' => '39269099', 'description' => 'Other articles of plastics', 'gst_rate' => '18%', 'cess' => '0%', 'input_credit' => 'Allowed', 'effective_from' => '2025-04-01', 'approval_status' => 'approved'],
        ];

        foreach ($rows as $row) {
            HsnTaxRule::updateOrCreate(['hsn' => $row['hsn']], $row);
        }
    }

    private function seedTemperatureClasses(): void
    {
        $rows = [
            ['code' => 'TEMP-AMBIENT', 'name' => 'Ambient', 'min_temp' => '15°C', 'max_temp' => '30°C', 'excursion' => '±5°C for 2 hours', 'monitoring' => 'Daily', 'alarm_required' => 'No', 'approval_status' => 'approved'],
            ['code' => 'TEMP-2-8', 'name' => 'Chilled +2°C to +8°C', 'min_temp' => '2°C', 'max_temp' => '8°C', 'excursion' => '15 minutes', 'monitoring' => 'Continuous', 'alarm_required' => 'Yes', 'approval_status' => 'approved'],
            ['code' => 'TEMP-M18', 'name' => 'Frozen -18°C', 'min_temp' => '-22°C', 'max_temp' => '-15°C', 'excursion' => '10 minutes', 'monitoring' => 'Continuous', 'alarm_required' => 'Yes', 'approval_status' => 'approved'],
        ];

        foreach ($rows as $row) {
            TemperatureClass::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    private function seedQualityParameters(array $categories): void
    {
        $rows = [
            ['code' => 'QC-CHEM-PURITY', 'name' => 'Chemical Purity', 'category' => 'CAT-CHEM', 'data_type' => 'Decimal', 'unit' => '%', 'min_value' => '98.00', 'max_value' => '100.00', 'sampling' => 'Per Batch', 'criticality' => 'Critical', 'approval_status' => 'approved'],
            ['code' => 'QC-PANEL-LEAK', 'name' => 'Panel Leakage Test', 'category' => 'CAT-PANEL', 'data_type' => 'Pass/Fail', 'unit' => 'NA', 'min_value' => 'Pass', 'max_value' => 'Pass', 'sampling' => 'AQL 1.5', 'criticality' => 'Critical', 'approval_status' => 'approved'],
            ['code' => 'QC-PANEL-WT', 'name' => 'Panel Weight', 'category' => 'CAT-PANEL', 'data_type' => 'Decimal', 'unit' => 'GM', 'min_value' => '490', 'max_value' => '510', 'sampling' => '5 per lot', 'criticality' => 'Major', 'approval_status' => 'approved'],
        ];

        foreach ($rows as $row) {
            $categoryCode = $row['category'];
            unset($row['category']);
            $row['tan90_item_category_id'] = $categories[$categoryCode]->id ?? null;

            QualityParameter::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    private function seedCustomers(): void
    {
        $rows = [
            ['code' => 'CUS-PHARMA-01', 'name' => 'Pharma Distribution Demo', 'gstin' => '29ABCDE1234F1Z5', 'segment' => 'Pharma', 'state' => 'Karnataka', 'city' => 'Bengaluru', 'credit_limit' => '₹10,00,000', 'payment_terms' => '30 Days', 'sales_owner' => 'Enterprise Sales', 'approval_status' => 'approved'],
            ['code' => 'CUS-FOOD-01', 'name' => 'Frozen Foods Demo', 'gstin' => '27ABCDE5678F1Z2', 'segment' => 'Frozen Foods', 'state' => 'Maharashtra', 'city' => 'Mumbai', 'credit_limit' => '₹7,50,000', 'payment_terms' => '45 Days', 'sales_owner' => 'Food Vertical', 'approval_status' => 'review'],
        ];

        foreach ($rows as $row) {
            Customer::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    private function seedTransporters(): void
    {
        $rows = [
            ['code' => 'TRN-RL', 'name' => 'Reliable Logistics', 'gstin' => '27AAECR4455K1Z8', 'service_type' => 'FTL / LTL', 'contact' => 'Dispatch Desk', 'phone' => '+91 90000 10001', 'email' => 'dispatch@reliable.demo', 'gps_tracking' => 'Yes', 'insurance' => 'Valid till 2027-03-31', 'approval_status' => 'approved'],
            ['code' => 'TRN-SF', 'name' => 'Safexpress Demo', 'gstin' => '06AAECS7788A1ZW', 'service_type' => 'Surface Express', 'contact' => 'Key Account Desk', 'phone' => '+91 90000 10002', 'email' => 'tan90@safexpress.demo', 'gps_tracking' => 'Yes', 'insurance' => 'Valid till 2026-12-31', 'approval_status' => 'approved'],
            ['code' => 'TRN-LOCAL', 'name' => 'Local Fleet Partner', 'service_type' => 'Local Delivery', 'contact' => 'Fleet Owner', 'phone' => '+91 90000 10003', 'email' => 'fleet@local.demo', 'gps_tracking' => 'No', 'insurance' => 'Pending', 'approval_status' => 'draft'],
        ];

        foreach ($rows as $row) {
            Transporter::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    private function seedMachines(array $plants): void
    {
        $rows = [
            ['code' => 'MCH-BF-001', 'name' => 'Blast Freezer 01', 'plant' => 'CHENNAI-TAN90-MU', 'machine_type' => 'Blast Freezer', 'manufacturer' => 'Demo Refrigeration', 'model' => 'BF-2500', 'serial_no' => 'BF2500-001', 'commissioned' => '2024-04-18', 'capacity' => '2500 KG/batch', 'maintenance_cycle' => 'Monthly', 'iot_enabled' => 'Yes', 'approval_status' => 'approved'],
            ['code' => 'MCH-PCM-001', 'name' => 'PCM Filling Line 01', 'plant' => 'CHENNAI-TAN90-MU', 'machine_type' => 'PCM Filling & Sealing', 'manufacturer' => 'Tan90 Custom', 'model' => 'PCM-FS-01', 'serial_no' => 'PCMFS-001', 'commissioned' => '2024-09-12', 'capacity' => '8000 sachets/shift', 'maintenance_cycle' => 'Fortnightly', 'iot_enabled' => 'Yes', 'approval_status' => 'approved'],
            ['code' => 'MCH-PNL-001', 'name' => 'Panel Assembly Line', 'plant' => 'DELHI-TAN90-MU', 'machine_type' => 'Panel Assembly', 'manufacturer' => 'Tan90 Custom', 'model' => 'PAN-AS-01', 'serial_no' => 'PANAS-001', 'commissioned' => '2025-01-15', 'capacity' => '1800 panels/shift', 'maintenance_cycle' => 'Monthly', 'iot_enabled' => 'No', 'approval_status' => 'review'],
        ];

        foreach ($rows as $row) {
            $plantCode = $row['plant'];
            unset($row['plant']);
            $row['tan90_plant_id'] = $plants[$plantCode]->id;

            Machine::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    private function seedWarehouseHierarchy($warehouses): void
    {
        $zone = WarehouseZone::updateOrCreate(['code' => 'CHN-CHEM-A1'], [
            'name' => 'Chemical Safe Zone A1', 'tan90_warehouse_id' => $warehouses['WH-CHN-RM']->id,
            'zone_type' => 'Chemical Safe Zone', 'allowed_material' => 'Chemicals', 'hazard_class' => 'Controlled',
            'approval_status' => 'approved',
        ]);
        $rack = Rack::updateOrCreate(['code' => 'CHN-CHEM-A1-RACK-A'], [
            'name' => 'Rack A', 'tan90_warehouse_zone_id' => $zone->id, 'capacity' => '2500 KG', 'approval_status' => 'approved',
        ]);
        $shelf = Shelf::updateOrCreate(['code' => 'CHN-CHEM-A1-SHELF-1'], [
            'name' => 'Shelf 1', 'tan90_rack_id' => $rack->id, 'capacity' => '600 KG', 'approval_status' => 'approved',
        ]);
        Bin::updateOrCreate(['code' => 'CHN-CHEM-A1-BIN-01'], [
            'name' => 'Bin A1-01', 'tan90_shelf_id' => $shelf->id, 'capacity' => '200 KG',
            'occupancy' => '62%', 'temperature' => '15-30°C', 'approval_status' => 'approved',
        ]);
    }

    /** @return array<string, ApprovalWorkflow> keyed by code */
    private function seedApprovalWorkflows(): array
    {
        $rows = [
            ['code' => 'WF-ITEM-CREATE', 'name' => 'Item Master Creation', 'module' => 'Item / SKU Master', 'trigger' => 'Create or critical edit', 'steps' => 'Requester -> Master Data Manager -> QC -> Finance', 'sla' => '24 Hours', 'escalation' => 'VP Operations', 'version_label' => 'v3', 'approval_status' => 'active'],
            ['code' => 'WF-VENDOR-ONBOARD', 'name' => 'Vendor Onboarding', 'module' => 'Vendor Master', 'trigger' => 'New vendor', 'steps' => 'Procurement -> GST Verification -> Finance -> Admin', 'sla' => '48 Hours', 'escalation' => 'Procurement Head', 'version_label' => 'v2', 'approval_status' => 'active'],
            ['code' => 'WF-LOCATION-GST', 'name' => 'Location GST Approval', 'module' => 'Locations & GST', 'trigger' => 'GST addition/change', 'steps' => 'Admin -> Finance -> Legal', 'sla' => '24 Hours', 'escalation' => 'CFO', 'version_label' => 'v1', 'approval_status' => 'draft'],
        ];

        return collect($rows)->mapWithKeys(fn ($row) => [$row['code'] => ApprovalWorkflow::updateOrCreate(['code' => $row['code']], $row)])->all();
    }

    private function seedApprovalWorkflowSteps(array $workflows): void
    {
        $rows = [
            ['code' => 'WF-ITEM-CREATE-1', 'workflow' => 'WF-ITEM-CREATE', 'step_order' => 1, 'step_role' => 'Master Data Manager', 'sla_hours' => 8],
            ['code' => 'WF-ITEM-CREATE-2', 'workflow' => 'WF-ITEM-CREATE', 'step_order' => 2, 'step_role' => 'QC', 'sla_hours' => 8],
            ['code' => 'WF-ITEM-CREATE-3', 'workflow' => 'WF-ITEM-CREATE', 'step_order' => 3, 'step_role' => 'Finance', 'sla_hours' => 8],
            ['code' => 'WF-VENDOR-ONBOARD-1', 'workflow' => 'WF-VENDOR-ONBOARD', 'step_order' => 1, 'step_role' => 'Procurement', 'sla_hours' => 24],
            ['code' => 'WF-VENDOR-ONBOARD-2', 'workflow' => 'WF-VENDOR-ONBOARD', 'step_order' => 2, 'step_role' => 'Finance', 'sla_hours' => 24],
        ];

        foreach ($rows as $row) {
            $workflowCode = $row['workflow'];
            unset($row['workflow']);
            $row['tan90_approval_workflow_id'] = $workflows[$workflowCode]->id;

            ApprovalWorkflowStep::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    private function seedNumberSeries(): void
    {
        $rows = [
            ['module' => 'Legal Entities', 'prefix' => 'LE-', 'pattern' => 'LE-{YYYY}-{####}', 'next_number' => 3, 'reset_policy' => 'Yearly', 'preview' => 'LE-2026-0003'],
            ['module' => 'Item / SKU Master', 'prefix' => 'SKU-', 'pattern' => '{CATEGORY}-{####}', 'next_number' => 241, 'reset_policy' => 'Never', 'preview' => 'CHEM-0241'],
            ['module' => 'Vendor Master', 'prefix' => 'VEN-', 'pattern' => 'VEN-{STATE}-{####}', 'next_number' => 45, 'reset_policy' => 'Never', 'preview' => 'VEN-MH-0045'],
            ['module' => 'Master Change Requests', 'prefix' => 'CR-', 'pattern' => 'CR-{YYYYMM}-{####}', 'next_number' => 98, 'reset_policy' => 'Monthly', 'preview' => 'CR-202607-0098'],
        ];

        foreach ($rows as $row) {
            NumberSeries::updateOrCreate(['module' => $row['module']], $row);
        }
    }

    private function seedSlaPolicies(): void
    {
        $rows = [
            ['code' => 'SLA-MASTER-NEW', 'name' => 'New Master Approval', 'applies_to' => 'All new master records', 'target' => '24 Hours', 'warning_at' => '18 Hours', 'escalate_at' => '24 Hours', 'escalation_role' => 'VP Operations', 'calendar' => 'Business Hours'],
            ['code' => 'SLA-VENDOR-GST', 'name' => 'Vendor GST Verification', 'applies_to' => 'Vendor onboarding', 'target' => '4 Hours', 'warning_at' => '2 Hours', 'escalate_at' => '4 Hours', 'escalation_role' => 'Finance Manager', 'calendar' => '24x7'],
            ['code' => 'SLA-CRITICAL-CHANGE', 'name' => 'Critical Master Change', 'applies_to' => 'Tax, UOM, SKU critical fields', 'target' => '8 Hours', 'warning_at' => '6 Hours', 'escalate_at' => '8 Hours', 'escalation_role' => 'CFO / VP Ops', 'calendar' => 'Business Hours'],
        ];

        foreach ($rows as $row) {
            SlaPolicy::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    private function seedDocumentRules(): void
    {
        $rows = [
            ['code' => 'DOC-VENDOR', 'name' => 'Vendor Onboarding Documents', 'entity' => 'Vendor', 'mandatory' => 'GST Certificate, PAN, Cancelled Cheque', 'optional' => 'MSME, ISO Certificate', 'max_size' => '10 MB', 'allowed_types' => 'PDF, JPG, PNG', 'retention' => '8 Years'],
            ['code' => 'DOC-LOCATION', 'name' => 'Location GST Documents', 'entity' => 'Location', 'mandatory' => 'GST Certificate, Address Proof', 'optional' => 'Lease Agreement', 'max_size' => '10 MB', 'allowed_types' => 'PDF, JPG, PNG', 'retention' => 'Permanent'],
            ['code' => 'DOC-MACHINE', 'name' => 'Machine Documents', 'entity' => 'Machine', 'mandatory' => 'Purchase Invoice, Commissioning Report', 'optional' => 'Warranty, AMC', 'max_size' => '20 MB', 'allowed_types' => 'PDF, JPG, PNG', 'retention' => 'Asset Life + 8 Years'],
        ];

        foreach ($rows as $row) {
            DocumentRule::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    private function seedNotificationTemplates(): void
    {
        $rows = [
            ['code' => 'NT-MASTER-APPROVAL', 'name' => 'Master Approval Required', 'channel' => 'Email + In-App', 'subject' => 'Approval required: {{record_type}} {{record_code}}', 'recipient' => 'Approver Role', 'trigger_event' => 'Record submitted', 'language' => 'English'],
            ['code' => 'NT-VENDOR-GST-FAIL', 'name' => 'Vendor GST Verification Failed', 'channel' => 'Email + In-App', 'subject' => 'GST verification issue for {{vendor_name}}', 'recipient' => 'Procurement + Finance', 'trigger_event' => 'GST verification failed', 'language' => 'English'],
            ['code' => 'NT-SLA-EXPIRY', 'name' => 'SLA Near Expiry', 'channel' => 'Email + In-App', 'subject' => 'SLA warning: {{record_code}}', 'recipient' => 'Owner + Escalation Role', 'trigger_event' => 'SLA warning threshold', 'language' => 'English'],
        ];

        foreach ($rows as $row) {
            NotificationTemplate::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    private function seedIntegrationConnections(): void
    {
        $rows = [
            ['code' => 'API-GST', 'name' => 'GST Verification Provider', 'type' => 'REST API', 'base_url' => null, 'auth' => 'API Key', 'environment' => 'Staging', 'health' => 'pending'],
            ['code' => 'API-MAPS', 'name' => 'Google Maps & Geocoding', 'type' => 'Browser SDK', 'base_url' => null, 'auth' => 'Restricted API Keys', 'environment' => 'Staging', 'health' => 'pending'],
            ['code' => 'API-MAIL', 'name' => 'SMTP Mail Service', 'type' => 'SMTP', 'base_url' => null, 'auth' => 'Username / Password', 'environment' => 'Staging', 'health' => 'pending'],
            ['code' => 'API-ZOHO', 'name' => 'Zoho Integration', 'type' => 'OAuth 2.0', 'base_url' => null, 'auth' => 'OAuth Refresh Token', 'environment' => 'Future', 'health' => 'disabled', 'status' => 'inactive'],
        ];

        foreach ($rows as $row) {
            IntegrationConnection::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    private function seedDataQualityRules(): void
    {
        $rows = [
            ['code' => 'DQ-VEN-GST', 'entity' => 'Vendor', 'description' => 'Vendor GSTIN missing or unverified', 'default_severity' => 'critical'],
            ['code' => 'DQ-LOC-GST', 'entity' => 'Location', 'description' => 'Location GSTIN missing or unverified', 'default_severity' => 'critical'],
            ['code' => 'DQ-VEN-DUP', 'entity' => 'Vendor', 'description' => 'Possible duplicate vendor by GSTIN', 'default_severity' => 'high'],
            ['code' => 'DQ-ITEM-HSN', 'entity' => 'Item', 'description' => 'Item missing HSN classification', 'default_severity' => 'medium'],
        ];

        foreach ($rows as $row) {
            DataQualityRule::updateOrCreate(['code' => $row['code']], $row);
        }
    }
}
