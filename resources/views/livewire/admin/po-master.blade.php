<?php

use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCustomFieldDefinition;
use App\Models\PurchaseOrderTermsTemplate;
use App\Models\User;
use App\Models\VendorMaster;
use App\Models\ZohoFieldDefinition;
use App\Models\Tan90\MasterData\Customer;
use App\Models\Tan90\MasterData\Location;
use App\Models\Tan90\MasterData\Warehouse;
use App\Services\AuditLogger;
use App\Services\PurchaseOrderTaxService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public bool $adding = false;
    public ?int $expanded = null;
    public string $poLookup = '';

    public string $poNumber = '';
    public string $subject = '';
    public string $vendorName = '';
    public string $status = 'Created';
    public string $poDate = '';
    public string $dueDate = '';
    public string $referenceNumber = '';
    public string $paymentTerms = '';
    public string $validUpto = '';
    public string $purchaseNature = '';
    public string $materialType = '';
    public string $pdfTemplate = 'standard';
    public string $termsTemplateId = '';
    public string $shipmentPreference = '';
    public string $billingBuilding = '';
    public string $billingStreet = '';
    public string $billingCountry = 'India';
    public string $billingCity = '';
    public string $billingState = '';
    public string $billingZip = '';
    public string $shippingBuilding = '';
    public string $shippingStreet = '';
    public string $shippingCountry = 'India';
    public string $shippingCity = '';
    public string $shippingState = '';
    public string $shippingZip = '';
    public string $shippingAddressMode = 'new';
    public bool $sameAsBillingAddress = false;
    public string $warehouseLocation = '';
    public string $warehouseSearch = '';
    public string $termsAndConditions = '';
    public array $headerCustomFields = [];
    // Zoho Inventory PO custom fields (zoho_field_definitions), keyed by Zoho api_name.
    public array $zohoFields = [];
    public bool $reverseCharge = false;
    public array $attachments = [];
    public string $vendorSearch = '';
    public bool $settingsOpen = false;
    public string $customFieldScope = 'header';
    public string $customFieldLabel = '';
    public string $customFieldType = 'text';
    public string $customFieldMaterialType = '';
    public ?int $editingFieldDefinitionId = null;
    public string $termsTemplateMaterialType = 'RM';
    public string $termsTemplateName = '';
    public string $termsTemplateBody = '';
    public ?int $editingTermsTemplateId = null;
    public array $items = [
        ['product' => '', 'hsnSac' => '', 'quantity' => '', 'uom' => 'PCS', 'listPrice' => '', 'customFields' => []],
    ];
    public array $statuses = ['Created', 'Approved', 'Delivered', 'Cancelled'];
    public array $purchaseNatures = ['Trading', 'Raw Material', 'PM Packaging Material', 'Others'];
    public array $materialTypes = ['RM', 'PM', 'Consumables', 'Capex', 'FG', 'Other'];

    public function mount(): void
    {
        $this->poLookup = trim((string) request()->query('po', ''));
        if ($this->poLookup !== '') {
            $this->expanded = PurchaseOrder::where('po_number', $this->poLookup)->value('id');
        }
    }

    /**
     * Nature is the first business decision in a PO. The category is always
     * derived from it; payment terms use the vendor master value when one is
     * available and otherwise fall back to the standard Net 30 term.
     */
    private const NATURE_CONFIG = [
        'Trading' => ['materialType' => 'FG', 'paymentTerms' => 'Net 30'],
        'Raw Material' => ['materialType' => 'RM', 'paymentTerms' => 'Net 30'],
        'PM Packaging Material' => ['materialType' => 'PM', 'paymentTerms' => 'Net 30'],
        'Others' => ['materialType' => 'Other', 'paymentTerms' => 'Net 30'],
    ];

    /** Searchable Indian state/UT list with common city suggestions. */
    private const INDIA_CITY_OPTIONS = [
        'Andaman and Nicobar Islands' => ['Port Blair', 'Diglipur', 'Mayabunder'],
        'Andhra Pradesh' => ['Visakhapatnam', 'Vijayawada', 'Guntur', 'Tirupati'],
        'Arunachal Pradesh' => ['Itanagar', 'Naharlagun', 'Tawang'],
        'Assam' => ['Guwahati', 'Dibrugarh', 'Silchar', 'Jorhat'],
        'Bihar' => ['Patna', 'Gaya', 'Muzaffarpur', 'Bhagalpur'],
        'Chandigarh' => ['Chandigarh'],
        'Chhattisgarh' => ['Raipur', 'Bhilai', 'Bilaspur', 'Korba'],
        'Dadra and Nagar Haveli and Daman and Diu' => ['Daman', 'Silvassa', 'Dadra'],
        'Delhi' => ['New Delhi', 'Delhi'],
        'Goa' => ['Panaji', 'Margao', 'Vasco da Gama'],
        'Gujarat' => ['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot'],
        'Haryana' => ['Gurugram', 'Faridabad', 'Panipat', 'Hisar'],
        'Himachal Pradesh' => ['Shimla', 'Dharamshala', 'Baddi', 'Mandi'],
        'Jammu and Kashmir' => ['Srinagar', 'Jammu', 'Anantnag'],
        'Jharkhand' => ['Ranchi', 'Jamshedpur', 'Dhanbad'],
        'Karnataka' => ['Bengaluru', 'Mysuru', 'Mangaluru', 'Hubballi'],
        'Kerala' => ['Thiruvananthapuram', 'Kochi', 'Kozhikode', 'Thrissur'],
        'Ladakh' => ['Leh', 'Kargil'],
        'Lakshadweep' => ['Kavaratti', 'Agatti'],
        'Madhya Pradesh' => ['Bhopal', 'Indore', 'Gwalior', 'Jabalpur'],
        'Maharashtra' => ['Mumbai', 'Pune', 'Thane', 'Nagpur', 'Bhiwandi'],
        'Manipur' => ['Imphal', 'Thoubal'],
        'Meghalaya' => ['Shillong', 'Tura'],
        'Mizoram' => ['Aizawl', 'Lunglei'],
        'Nagaland' => ['Kohima', 'Dimapur'],
        'Odisha' => ['Bhubaneswar', 'Cuttack', 'Rourkela'],
        'Puducherry' => ['Puducherry', 'Karaikal', 'Mahe'],
        'Punjab' => ['Amritsar', 'Ludhiana', 'Jalandhar', 'Mohali'],
        'Rajasthan' => ['Jaipur', 'Jodhpur', 'Udaipur', 'Kota'],
        'Sikkim' => ['Gangtok', 'Namchi'],
        'Tamil Nadu' => ['Chennai', 'Coimbatore', 'Madurai', 'Tiruchirappalli', 'Salem'],
        'Telangana' => ['Hyderabad', 'Warangal', 'Nizamabad'],
        'Tripura' => ['Agartala', 'Udaipur'],
        'Uttar Pradesh' => ['Lucknow', 'Noida', 'Kanpur', 'Varanasi', 'Agra', 'Ghaziabad'],
        'Uttarakhand' => ['Dehradun', 'Haridwar', 'Haldwani', 'Rishikesh'],
        'West Bengal' => ['Kolkata', 'Howrah', 'Siliguri', 'Durgapur'],
    ];

    public function addItem(): void
    {
        $this->items[] = ['product' => '', 'hsnSac' => '', 'quantity' => '', 'uom' => 'PCS', 'listPrice' => '', 'customFields' => []];
    }

    public function openForm(): void
    {
        $this->adding = true;
    }

    public function updatedPurchaseNature(): void
    {
        $this->applyNatureDefaults();
        $this->termsTemplateId = '';
        $this->termsAndConditions = '';
        $this->resetValidation(['purchaseNature', 'materialType', 'paymentTerms', 'termsTemplateId']);
    }

    private function applyNatureDefaults(): void
    {
        $defaults = self::NATURE_CONFIG[$this->purchaseNature] ?? null;
        if (! $defaults) {
            return;
        }

        $this->materialType = $defaults['materialType'];

        $vendorTerm = $this->vendorName
            ? VendorMaster::where('vendor_name', $this->vendorName)->value('payment_terms')
            : null;
        $this->paymentTerms = (string) ($vendorTerm ?: $defaults['paymentTerms']);
    }

    public function updatedMaterialType(): void
    {
        if ($this->purchaseNature && isset(self::NATURE_CONFIG[$this->purchaseNature])) {
            $this->materialType = self::NATURE_CONFIG[$this->purchaseNature]['materialType'];
        }

        $template = $this->termsTemplateId
            ? PurchaseOrderTermsTemplate::whereKey($this->termsTemplateId)->where('material_type', $this->materialType)->where('active', true)->first()
            : null;
        if (! $template) {
            $this->termsTemplateId = '';
            $this->termsAndConditions = '';
        }
    }

    public function updatedTermsTemplateId(): void
    {
        if ($this->termsTemplateId === '') {
            return;
        }

        $template = PurchaseOrderTermsTemplate::whereKey($this->termsTemplateId)
            ->where('material_type', $this->materialType)
            ->where('active', true)
            ->first();

        if ($template) {
            $this->termsAndConditions = $template->body;
        }
    }

    public function removeItem(int $index): void
    {
        if (count($this->items) === 1) {
            return;
        }

        array_splice($this->items, $index, 1);
    }

    public function selectVendor(string $vendorName): void
    {
        $vendor = VendorMaster::where('vendor_name', $vendorName)->firstOrFail();

        $this->vendorName = $vendorName;
        $this->vendorSearch = $vendorName;
        // Selecting a vendor is the single source for vendor billing and
        // payment defaults once the PO nature has been chosen.
        $this->billingBuilding = (string) ($vendor->address_building ?: $vendor->vendor_name);
        $this->billingStreet = (string) ($vendor->address_street ?: '');
        $this->billingCity = (string) ($vendor->address_city ?: '');
        $this->billingState = (string) ($vendor->address_state ?: '');
        $this->billingZip = (string) ($vendor->address_zip ?: '');
        $this->paymentTerms = '';
        $this->applyNatureDefaults();
        if ($this->shippingAddressMode === 'existing') {
            $this->useVendorAddress();
        }
    }

    public function clearVendor(): void
    {
        $hadExistingAddress = $this->shippingAddressMode === 'existing';
        $this->vendorName = '';
        $this->vendorSearch = '';
        $this->paymentTerms = self::NATURE_CONFIG[$this->purchaseNature]['paymentTerms'] ?? '';
        $this->shippingAddressMode = 'new';
        if ($hadExistingAddress) {
            $this->shippingBuilding = '';
            $this->shippingStreet = '';
            $this->shippingCity = '';
            $this->shippingState = '';
            $this->shippingZip = '';
        }
    }

    public function updatedShippingAddressMode(string $value): void
    {
        if ($value === 'existing') {
            $this->useVendorAddress();
        } else {
            $this->startNewDeliveryAddress();
        }
    }

    public function updatedWarehouseLocation(string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $warehouse = Warehouse::with('location')->where('name', $value)->first();
        $location = $warehouse?->location ?? Location::where('name', $value)->first();
        $customer = $location ? null : Customer::where('name', $value)->first();

        if (! $warehouse && ! $location && ! $customer) {
            return;
        }

        $this->sameAsBillingAddress = false;
        $this->shippingAddressMode = 'new';
        $this->shippingBuilding = (string) ($warehouse?->name ?? $location?->name ?? $customer?->name ?? '');
        $this->shippingStreet = (string) ($location?->address ?? '');
        $this->shippingCity = (string) ($location?->city ?? $customer?->city ?? '');
        $this->shippingState = (string) ($location?->state ?? $customer?->state ?? '');
        $this->shippingZip = (string) ($location?->pincode ?? '');
        $this->resetValidation(['shippingBuilding', 'shippingStreet', 'shippingCity', 'shippingState', 'shippingZip']);
    }

    public function startNewDeliveryAddress(): void
    {
        $this->shippingAddressMode = 'new';
        $this->sameAsBillingAddress = false;
        $this->shippingBuilding = '';
        $this->shippingStreet = '';
        $this->shippingCity = '';
        $this->shippingState = '';
        $this->shippingZip = '';
        $this->resetValidation(['shippingBuilding', 'shippingStreet', 'shippingCity', 'shippingState', 'shippingZip']);
    }

    public function updatedSameAsBillingAddress(bool $value): void
    {
        if (! $value) {
            return;
        }

        $this->shippingAddressMode = 'new';
        $this->shippingBuilding = $this->billingBuilding;
        $this->shippingStreet = $this->billingStreet;
        $this->shippingCity = $this->billingCity;
        $this->shippingState = $this->billingState;
        $this->shippingZip = $this->billingZip;
    }

    public function updated($property, $value): void
    {
        if (! $this->sameAsBillingAddress || ! str_starts_with((string) $property, 'billing')) {
            return;
        }

        $map = [
            'billingBuilding' => 'shippingBuilding',
            'billingStreet' => 'shippingStreet',
            'billingCity' => 'shippingCity',
            'billingState' => 'shippingState',
            'billingZip' => 'shippingZip',
        ];

        if (isset($map[$property])) {
            $this->{$map[$property]} = (string) $value;
        }
    }

    public function useVendorAddress(): void
    {
        $vendor = $this->vendorName
            ? VendorMaster::where('vendor_name', $this->vendorName)->first()
            : null;

        if (! $vendor) {
            $this->shippingAddressMode = 'new';
            return;
        }

        $this->shippingBuilding = (string) ($vendor->address_building ?: $vendor->vendor_name);
        $this->shippingStreet = (string) ($vendor->address_street ?: '');
        $this->shippingCity = (string) ($vendor->address_city ?: '');
        $this->shippingState = (string) ($vendor->address_state ?: '');
        $this->shippingZip = (string) ($vendor->address_zip ?: '');
        $this->shippingAddressMode = 'existing';
    }

    private function cityOptionsForState(?string $state): array
    {
        foreach (self::INDIA_CITY_OPTIONS as $name => $cities) {
            if (strcasecmp($name, trim((string) $state)) === 0) {
                return $cities;
            }
        }

        return [];
    }

    public function updatedVendorSearch(string $value): void
    {
        if ($this->vendorName && strcasecmp(trim($value), $this->vendorName) !== 0) {
            $this->vendorName = '';
        }
    }

    public function addPo(): void
    {
        $this->persistPo(false);
    }

    public function saveAndSubmit(): void
    {
        // Draft creation and vendor release are explicit two-step actions so
        // the manager can review a saved PO before exposing it externally.
        $this->persistPo(true, false);
    }

    // Blank PO Number = next number from the PO category's series (Master Data >
    // Number Series, restarts each April). A typed number is used as before.
    private function fillPoNumberFromSeries(): void
    {
        if (trim($this->poNumber) !== '' || $this->purchaseNature === '') {
            return;
        }
        $series = app(\App\Services\Tan90\MasterData\NumberSeriesService::class);
        for ($try = 0; $try < 50; $try++) {
            $number = $series->next('Purchase Order: '.$this->purchaseNature);
            if ($number === null) {
                return;
            }
            if (! PurchaseOrder::where('po_number', $number)->exists()) {
                $this->poNumber = $number;

                return;
            }
        }
    }

    private function persistPo(bool $submit, bool $releaseToVendor = false): void
    {
        // Never trust a separately submitted category/term. Nature of PO is
        // the source of truth for the PO classification.
        $this->applyNatureDefaults();
        $this->fillPoNumberFromSeries();

        $this->validate([
            'poNumber' => ['required', 'string', 'max:255', 'unique:purchase_orders,po_number'],
            'vendorName' => ['required', 'string', 'exists:vendor_masters,vendor_name'],
            'purchaseNature' => ['required', 'in:Trading,Raw Material,PM Packaging Material,Others'],
            'materialType' => ['required', 'in:RM,PM,Consumables,Capex,FG,Other'],
            'pdfTemplate' => ['required', 'in:standard,compact'],
            'poDate' => ['nullable', 'date'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:poDate'],
            'validUpto' => ['nullable', 'date', 'after_or_equal:poDate'],
            'billingBuilding' => ['required', 'string', 'max:255'],
            'billingStreet' => ['required', 'string', 'max:255'],
            'billingCity' => ['required', 'string', 'max:100'],
            'billingState' => ['required', 'string', 'max:100'],
            'billingZip' => ['required', 'string', 'max:20'],
            // State/GST-code validation is intentionally deferred for now. The
            // address should not block PO creation while master-data mapping is
            // being finalized; tax calculation already fails safe when the
            // place of supply cannot be mapped.
            'shippingState' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.uom' => ['required', 'string', 'in:KG,GRAM,PCS,BOX,METER,LITER,ROLL,SET'],
            'items.*.listPrice' => ['required', 'numeric', 'min:0'],
            'items.*.hsnSac' => ['nullable', 'string', 'max:30'],
            'termsTemplateId' => ['nullable', 'integer', 'exists:purchase_order_terms_templates,id'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        // Zoho's PO custom fields: same required flags / dropdown values as Zoho.
        $zohoRules = [];
        $zohoNames = [];
        foreach (ZohoFieldDefinition::forEntity('purchaseorder')->get() as $definition) {
            $builtIn = ZohoFieldDefinition::PO_BUILT_IN[$definition->api_name] ?? null;
            $key = $builtIn ?? 'zohoFields.'.$definition->api_name;
            $zohoRules[$key] = $builtIn === 'validUpto'
                ? [$definition->is_mandatory ? 'required' : 'nullable', 'date', 'after_or_equal:poDate']
                : $definition->rules();
            $zohoNames[$key] = $definition->label;
        }
        if ($zohoRules) {
            $this->validate($zohoRules, [], $zohoNames);
        }

        $taxService = app(PurchaseOrderTaxService::class);
        if ($this->termsTemplateId !== '') {
            $template = PurchaseOrderTermsTemplate::whereKey($this->termsTemplateId)->where('material_type', $this->materialType)->where('active', true)->first();
            if (! $template) {
                throw ValidationException::withMessages(['termsTemplateId' => 'Choose an active terms template for this material type.']);
            }
        }
        $headerDefinitions = PurchaseOrderCustomFieldDefinition::where('scope', 'header')
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('material_type')->orWhere('material_type', $this->materialType))
            ->get();
        $headerValues = $headerDefinitions->mapWithKeys(fn ($definition) => [
            $definition->label => $this->headerCustomFields[$definition->field_key] ?? null,
        ])->filter(fn ($value) => $value !== null && $value !== '')->all();

        $po = PurchaseOrder::create([
            'po_number' => $this->poNumber,
            'subject' => $this->subject ?: null,
            'vendor_name' => $this->vendorName,
            'status' => $releaseToVendor ? 'Approved' : 'Created',
            'submitted_at' => $submit ? now() : null,
            'po_date' => $this->poDate ?: null,
            'released_at' => $releaseToVendor ? now() : null,
            'due_date' => $this->dueDate ?: null,
            'reference_number' => $this->referenceNumber ?: null,
            'payment_terms' => $this->paymentTerms ?: null,
            'valid_upto' => $this->validUpto ?: null,
            'purchase_nature' => $this->purchaseNature ?: null,
            'material_type' => $this->materialType,
            'pdf_template' => $this->pdfTemplate,
            'terms_template_id' => $this->termsTemplateId ?: null,
            'shipment_preference' => $this->shipmentPreference ?: null,
            'reverse_charge' => $this->reverseCharge,
            'billing_country' => $this->billingCountry ?: 'India',
            'billing_building' => $this->billingBuilding,
            'billing_street' => $this->billingStreet,
            'billing_city' => $this->billingCity ?: null,
            'billing_state' => $this->billingState ?: null,
            'billing_zip' => $this->billingZip ?: null,
            'shipping_country' => $this->shippingCountry ?: 'India',
            'shipping_building' => $this->shippingBuilding ?: null,
            'shipping_street' => $this->shippingStreet ?: null,
            'shipping_city' => $this->shippingCity ?: null,
            'shipping_state' => $this->shippingState ?: null,
            'shipping_zip' => $this->shippingZip ?: null,
            'warehouse_location' => $this->warehouseLocation ?: null,
            'terms_and_conditions' => $this->termsAndConditions ?: null,
            'header_custom_fields' => $headerValues,
            'zoho_custom_fields' => array_filter($this->zohoFields, fn ($value) => $value !== '' && $value !== null) ?: null,
        ]);

        foreach ($this->items as $item) {
            $rate = $taxService->rateForHsn($item['hsnSac'] ?? null) ?? 0.0;
            $lineTax = $taxService->breakdown(
                (float) $item['quantity'] * (float) $item['listPrice'],
                0,
                (float) $rate,
                $this->shippingState,
                $this->reverseCharge,
            );
            $lineDefinitions = PurchaseOrderCustomFieldDefinition::where('scope', 'item')
                ->where('active', true)
                ->where(fn ($query) => $query->whereNull('material_type')->orWhere('material_type', $this->materialType))
                ->get();
            $lineValues = $lineDefinitions->mapWithKeys(fn ($definition) => [
                $definition->label => $item['customFields'][$definition->field_key] ?? null,
            ])->filter(fn ($value) => $value !== null && $value !== '')->all();

            $po->lines()->create([
                'product' => trim($item['product']),
                'hsn_sac' => $item['hsnSac'] ?: null,
                'quantity' => $item['quantity'],
                'uom' => $item['uom'],
                'list_price' => $item['listPrice'],
                'gst_rate' => $rate,
                'tax' => $lineTax['cgst'] + $lineTax['sgst'] + $lineTax['igst'],
                'custom_fields' => $lineValues,
            ]);
        }

        if ($this->attachments !== []) {
            $po->update([
                'attachments' => collect($this->attachments)->map(fn ($file) => [
                    'name' => $file->getClientOriginalName(),
                    'path' => $file->store('purchase-order-attachments', 'local'),
                    'size' => $file->getSize(),
                ])->values()->all(),
            ]);
        }

        AuditLogger::log($releaseToVendor ? 'Purchase Order released to vendor' : ($submit ? 'Purchase Order submitted for release' : 'Purchase Order saved as draft'), "{$po->po_number} · {$po->vendor_name}", $po);

        $this->reset(['poNumber', 'subject', 'vendorName', 'poDate', 'dueDate', 'referenceNumber', 'paymentTerms', 'validUpto', 'purchaseNature', 'materialType', 'pdfTemplate', 'termsTemplateId', 'shipmentPreference', 'billingBuilding', 'billingStreet', 'billingCity', 'billingState', 'billingZip', 'shippingBuilding', 'shippingStreet', 'shippingCity', 'shippingState', 'shippingZip', 'warehouseLocation', 'warehouseSearch', 'termsAndConditions', 'headerCustomFields', 'zohoFields', 'reverseCharge', 'attachments', 'vendorSearch', 'adding', 'shippingAddressMode', 'sameAsBillingAddress']);
        $this->billingCountry = $this->shippingCountry = 'India';
        $this->purchaseNature = '';
        $this->materialType = '';
        $this->pdfTemplate = 'standard';
        $this->items = [['product' => '', 'hsnSac' => '', 'quantity' => '', 'uom' => 'PCS', 'listPrice' => '', 'customFields' => []]];
        $this->status = 'Created';
        if ($releaseToVendor) {
            session()->flash('po-released', "{$po->po_number} released to {$po->vendor_name}; it is now available in the vendor portal for acknowledgement.");
        } else {
            session()->flash('po-saved', $submit ? 'PO submitted for release. Use Release to vendor when it is approved.' : 'PO saved as a draft.');
        }
    }

    public function cancelPo(): void
    {
        $this->reset(['adding', 'poNumber', 'subject', 'vendorName', 'poDate', 'dueDate', 'referenceNumber', 'paymentTerms', 'validUpto', 'purchaseNature', 'billingBuilding', 'billingStreet', 'billingCity', 'billingState', 'billingZip', 'shippingBuilding', 'shippingStreet', 'shippingCity', 'shippingState', 'shippingZip', 'warehouseLocation', 'warehouseSearch', 'termsAndConditions', 'headerCustomFields', 'zohoFields', 'attachments', 'vendorSearch', 'shippingAddressMode', 'sameAsBillingAddress']);
        $this->items = [['product' => '', 'hsnSac' => '', 'quantity' => '', 'uom' => 'PCS', 'listPrice' => '', 'customFields' => []]];
        $this->billingCountry = $this->shippingCountry = 'India';
        $this->materialType = '';
        $this->purchaseNature = '';
        $this->paymentTerms = '';
        $this->pdfTemplate = 'standard';
        $this->termsTemplateId = '';
        $this->reverseCharge = false;
    }

    public function editCustomFieldDefinition(int $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $definition = PurchaseOrderCustomFieldDefinition::findOrFail($id);
        $this->editingFieldDefinitionId = $definition->id;
        $this->customFieldScope = $definition->scope;
        $this->customFieldLabel = $definition->label;
        $this->customFieldType = $definition->input_type;
        $this->customFieldMaterialType = $definition->material_type ?? '';
        $this->settingsOpen = true;
    }

    public function saveCustomFieldDefinition(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->validate([
            'customFieldScope' => ['required', 'in:header,item'],
            'customFieldLabel' => ['required', 'string', 'max:80'],
            'customFieldType' => ['required', 'in:text,number,date,textarea'],
            'customFieldMaterialType' => ['nullable', 'in:RM,PM,Consumables,Capex,FG,Other'],
        ]);
        $definition = $this->editingFieldDefinitionId
            ? PurchaseOrderCustomFieldDefinition::findOrFail($this->editingFieldDefinitionId)
            : new PurchaseOrderCustomFieldDefinition();
        $key = $definition->field_key ?: Str::slug($this->customFieldLabel).'-'.$this->customFieldScope.'-'.substr(sha1($this->customFieldMaterialType ?: 'all'), 0, 6);
        $baseKey = $key;
        $suffix = 2;
        while (PurchaseOrderCustomFieldDefinition::where('field_key', $key)->when($definition->exists, fn ($query) => $query->where('id', '!=', $definition->id))->exists()) {
            $key = $baseKey.'-'.$suffix++;
        }
        $definition->fill([
            'scope' => $this->customFieldScope,
            'field_key' => $key,
            'label' => $this->customFieldLabel,
            'input_type' => $this->customFieldType,
            'material_type' => $this->customFieldMaterialType ?: null,
            'active' => true,
        ])->save();
        $this->reset(['editingFieldDefinitionId', 'customFieldLabel']);
        session()->flash('po-settings-saved', 'Custom field saved.');
    }

    public function deleteCustomFieldDefinition(int $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        PurchaseOrderCustomFieldDefinition::findOrFail($id)->delete();
        session()->flash('po-settings-saved', 'Custom field removed from future POs.');
    }

    public function editTermsTemplate(int $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $template = PurchaseOrderTermsTemplate::findOrFail($id);
        $this->editingTermsTemplateId = $template->id;
        $this->termsTemplateMaterialType = $template->material_type;
        $this->termsTemplateName = $template->name;
        $this->termsTemplateBody = $template->body;
        $this->settingsOpen = true;
    }

    public function saveTermsTemplate(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->validate([
            'termsTemplateMaterialType' => ['required', 'in:RM,PM,Consumables,Capex,FG,Other'],
            'termsTemplateName' => ['required', 'string', 'max:100'],
            'termsTemplateBody' => ['required', 'string', 'max:10000'],
        ]);
        $template = $this->editingTermsTemplateId
            ? PurchaseOrderTermsTemplate::findOrFail($this->editingTermsTemplateId)
            : new PurchaseOrderTermsTemplate();
        $duplicate = PurchaseOrderTermsTemplate::where('material_type', $this->termsTemplateMaterialType)
            ->where('name', $this->termsTemplateName)
            ->when($template->exists, fn ($query) => $query->where('id', '!=', $template->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['termsTemplateName' => 'A template with this name already exists for the selected material type.']);
        }
        $template->fill([
            'material_type' => $this->termsTemplateMaterialType,
            'name' => $this->termsTemplateName,
            'body' => $this->termsTemplateBody,
            'active' => true,
        ])->save();
        $this->reset(['editingTermsTemplateId', 'termsTemplateName', 'termsTemplateBody']);
        session()->flash('po-settings-saved', 'Terms template saved.');
    }

    public function deleteTermsTemplate(int $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        PurchaseOrderTermsTemplate::findOrFail($id)->delete();
        session()->flash('po-settings-saved', 'Terms template removed from future POs.');
    }

    public function deletePo(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        AuditLogger::log('Purchase Order removed from master', $po->po_number);
        $po->delete();
    }

    /** Publishes a draft PO to the vendor's portal. */
    public function releasePo(int $id): void
    {
        $po = PurchaseOrder::with('lines')->findOrFail($id);

        if ($po->isReleased()) {
            return;
        }

        if (! $po->billing_building || ! $po->billing_street || ! $po->billing_city || ! $po->billing_state || ! $po->billing_zip) {
            throw ValidationException::withMessages(['po' => 'Complete the PO billing address before releasing it.']);
        }
        // Delivery-state/GST-code validation is intentionally bypassed for
        // now. Tax breakdown remains fail-safe for an unmapped place of supply.
        $taxService = app(PurchaseOrderTaxService::class);
        foreach ($po->lines as $line) {
            $rate = $line->gst_rate ?? $taxService->rateForHsn($line->hsn_sac) ?? 0.0;
            $breakdown = $taxService->breakdown((float) $line->quantity * (float) $line->list_price, (float) $line->discount, (float) $rate, $po->shipping_state, (bool) $po->reverse_charge);
            $line->forceFill(['gst_rate' => $rate, 'tax' => $breakdown['cgst'] + $breakdown['sgst'] + $breakdown['igst']])->save();
        }

        // Confirmed live: releasing a PO only ever set released_at -- the
        // PO's own status column (Created/Approved/Delivered/Cancelled)
        // never moved off "Created", even though release IS the approval
        // step from the vendor's point of view.
        $po->update([
            'released_at' => now(),
            'status' => $po->status === 'Created' ? 'Approved' : $po->status,
        ]);
        AuditLogger::log('Purchase Order released to vendor', "{$po->po_number} · {$po->vendor_name}", $po);
        session()->flash('po-released', "{$po->po_number} released to {$po->vendor_name}; it is now available in the vendor portal.");
    }

    public function with(): array
    {
        // Confirmed live: VendorMaster holds ~190 real vendor companies, but
        // only a handful have an actual portal login (a User with
        // role=Vendor and a matching name) -- a PO raised against any other
        // vendor name is a dead end, since nothing else in the app
        // provisions that login. Surfaced here rather than silently
        // discovered when the vendor never sees their PO.
        $loginVendorNames = User::where('role', Role::Vendor)->pluck('name');
        $selectedVendor = $this->vendorName
            ? VendorMaster::where('vendor_name', $this->vendorName)->first()
            : null;

        $deliveryLocations = Warehouse::with('location')->orderBy('name')->get()->map(fn ($warehouse) => [
            'value' => $warehouse->name,
            'label' => $warehouse->name.' · '.($warehouse->location?->city ?: 'Location not mapped').($warehouse->location?->state ? ', '.$warehouse->location->state : ''),
        ])->merge(Location::orderBy('name')->get()->map(fn ($location) => [
            'value' => $location->name,
            'label' => $location->name.' · '.($location->city ?: 'City not mapped').($location->state ? ', '.$location->state : ''),
        ]))->merge(Customer::orderBy('name')->get()->map(fn ($customer) => [
            'value' => $customer->name,
            'label' => $customer->name.' · customer · '.($customer->city ?: 'City not mapped').($customer->state ? ', '.$customer->state : ''),
        ]))->unique('value')->values();

        if (trim($this->warehouseSearch) !== '') {
            $needle = Str::lower(trim($this->warehouseSearch));
            $deliveryLocations = $deliveryLocations->filter(fn ($location) => Str::contains(Str::lower($location['value'].' '.$location['label']), $needle))->values();
        }

        return [
            'orders' => PurchaseOrder::with(['lines', 'acknowledgement'])
                ->when($this->poLookup !== '', fn ($query) => $query->where('po_number', 'like', '%'.$this->poLookup.'%'))
                ->orderByDesc('created_at')->get(),
            'vendors' => VendorMaster::when($this->vendorSearch, fn ($query) => $query->where(function ($q) {
                $term = '%'.$this->vendorSearch.'%';
                $q->where('vendor_name', 'like', $term)->orWhere('gst_number', 'like', $term);
            }))->orderBy('vendor_name')->limit(50)->get(),
            'loginVendorNames' => $loginVendorNames,
            'zohoPoFields' => ZohoFieldDefinition::forEntity('purchaseorder')->whereNotIn('api_name', array_keys(ZohoFieldDefinition::PO_BUILT_IN))->get(),
            'validUptoRequired' => ZohoFieldDefinition::forEntity('purchaseorder')->where('api_name', 'cf_po_valid_upto')->where('is_mandatory', true)->exists(),
            'headerFieldDefinitions' => PurchaseOrderCustomFieldDefinition::where('scope', 'header')->where('active', true)->where(fn ($query) => $query->whereNull('material_type')->orWhere('material_type', $this->materialType))->orderBy('sort_order')->orderBy('label')->get(),
            'itemFieldDefinitions' => PurchaseOrderCustomFieldDefinition::where('scope', 'item')->where('active', true)->where(fn ($query) => $query->whereNull('material_type')->orWhere('material_type', $this->materialType))->orderBy('sort_order')->orderBy('label')->get(),
            'termsTemplates' => PurchaseOrderTermsTemplate::where('material_type', $this->materialType)->where('active', true)->orderBy('name')->get(),
            'allFieldDefinitions' => auth()->user()?->isAdmin() ? PurchaseOrderCustomFieldDefinition::orderBy('scope')->orderBy('label')->get() : collect(),
            'allTermsTemplates' => auth()->user()?->isAdmin() ? PurchaseOrderTermsTemplate::orderBy('material_type')->orderBy('name')->get() : collect(),
            'indiaStates' => array_keys(self::INDIA_CITY_OPTIONS),
            'billingCityOptions' => $this->cityOptionsForState($this->billingState),
            'shippingCityOptions' => $this->cityOptionsForState($this->shippingState),
            'selectedVendorAddress' => $selectedVendor,
            'deliveryLocations' => $deliveryLocations,
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager / Procurement</div>
                <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Purchase Orders</h1>
                <p class="text-sm mt-1" style="color: var(--text-secondary);">Save a draft for later, or save and release a complete PO directly to the selected vendor portal.</p>
            </div>
            <div class="flex items-center gap-2">
                @if (auth()->user()?->isAdmin())
                    <button wire:click="$toggle('settingsOpen')" type="button" class="rounded-xl px-3 py-2.5 text-sm font-semibold border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">⚙ PO settings</button>
                @endif
                @if ($adding)
                    <button type="button" wire:click="cancelPo" class="inline-flex items-center justify-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Cancel</button>
                @else
                    <button type="button" wire:click="openForm" class="inline-flex items-center justify-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Add PO</button>
                @endif
            </div>
        </div>
    </section>

    @if (session('po-saved'))
        <div class="rounded-lg border px-4 py-3 mb-4 text-sm" role="status" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">{{ session('po-saved') }}</div>
    @endif
    @if (session('po-settings-saved'))
        <div class="rounded-lg border px-4 py-3 mb-4 text-sm" role="status" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">{{ session('po-settings-saved') }}</div>
    @endif
    @if (session('po-released'))
        <div class="rounded-lg border px-4 py-3 mb-4 text-sm" role="status" aria-live="polite" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">{{ session('po-released') }}</div>
    @endif
    @error('po')
        <div class="rounded-lg border px-4 py-3 mb-4 text-sm" role="alert" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">{{ $message }}</div>
    @enderror

    @if ($settingsOpen && auth()->user()?->isAdmin())
        <section class="rounded-xl border p-4 mb-4 space-y-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex items-start justify-between gap-3">
                <div><h2 class="font-semibold" style="color: var(--text-primary);">PO configuration</h2><p class="text-xs mt-1" style="color: var(--text-secondary);">Manage future header/item fields and material-specific terms. Existing PO values remain unchanged.</p></div>
                <button type="button" wire:click="$set('settingsOpen', false)" class="text-sm underline" style="color: var(--text-secondary);">Close</button>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="rounded-lg border p-3 space-y-3" style="border-color: var(--border);">
                    <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Custom fields</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <select wire:model="customFieldScope" class="rounded-lg border px-2 py-2 text-sm" style="border-color: var(--border);"><option value="header">PO header</option><option value="item">Each item</option></select>
                        <select wire:model="customFieldType" class="rounded-lg border px-2 py-2 text-sm" style="border-color: var(--border);"><option value="text">Text</option><option value="textarea">Long text</option><option value="number">Number</option><option value="date">Date</option></select>
                        <input wire:model="customFieldLabel" class="rounded-lg border px-3 py-2 text-sm col-span-2" style="border-color: var(--border);" placeholder="Field label" />
                        <select wire:model="customFieldMaterialType" class="rounded-lg border px-2 py-2 text-sm col-span-2" style="border-color: var(--border);"><option value="">All material types</option>@foreach ($materialTypes as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select>
                    </div>
                    @error('customFieldLabel') <span class="block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    <button type="button" wire:click="saveCustomFieldDefinition" class="rounded-lg px-3 py-2 text-sm font-semibold text-white" style="background: var(--brand);">{{ $editingFieldDefinitionId ? 'Update field' : 'Add field' }}</button>
                    <div class="space-y-1">
                        @forelse ($allFieldDefinitions as $definition)
                            <div class="flex items-center justify-between gap-2 border-t pt-2 text-xs" style="border-color: var(--border);"><span>{{ $definition->label }} · {{ $definition->scope }} · {{ $definition->material_type ?? 'All types' }}</span><span class="flex gap-2"><button type="button" wire:click="editCustomFieldDefinition({{ $definition->id }})" class="underline">Edit</button><button type="button" wire:click="deleteCustomFieldDefinition({{ $definition->id }})" wire:confirm="Remove this field from future POs?" class="underline" style="color: var(--status-critical);">Remove</button></span></div>
                        @empty <p class="text-xs" style="color: var(--text-muted);">No custom fields configured.</p> @endforelse
                    </div>
                </div>
                <div class="rounded-lg border p-3 space-y-3" style="border-color: var(--border);">
                    <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Terms &amp; conditions templates</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <select wire:model="termsTemplateMaterialType" class="rounded-lg border px-2 py-2 text-sm" style="border-color: var(--border);">@foreach ($materialTypes as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select>
                        <input wire:model="termsTemplateName" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Template name" />
                        <textarea wire:model="termsTemplateBody" rows="4" class="rounded-lg border px-3 py-2 text-sm col-span-2" style="border-color: var(--border);" placeholder="Terms shown on the PO"></textarea>
                    </div>
                    @error('termsTemplateName') <span class="block text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    <button type="button" wire:click="saveTermsTemplate" class="rounded-lg px-3 py-2 text-sm font-semibold text-white" style="background: var(--brand);">{{ $editingTermsTemplateId ? 'Update template' : 'Add template' }}</button>
                    <div class="space-y-1">
                        @forelse ($allTermsTemplates as $template)
                            <div class="flex items-center justify-between gap-2 border-t pt-2 text-xs" style="border-color: var(--border);"><span>{{ $template->material_type }} · {{ $template->name }}</span><span class="flex gap-2"><button type="button" wire:click="editTermsTemplate({{ $template->id }})" class="underline">Edit</button><button type="button" wire:click="deleteTermsTemplate({{ $template->id }})" wire:confirm="Remove this terms template?" class="underline" style="color: var(--status-critical);">Remove</button></span></div>
                        @empty <p class="text-xs" style="color: var(--text-muted);">No templates configured.</p> @endforelse
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if ($adding)
        <div class="rounded-lg border p-4 mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3" style="background: var(--surface-3); border-color: var(--border);">
            @if ($errors->any())
                <div id="po-validation-summary" role="alert" aria-live="assertive" tabindex="-1" class="sm:col-span-3 rounded-lg border px-4 py-3" style="border-color:var(--status-critical);background:var(--status-critical-bg);color:var(--status-critical);">
                    <p class="font-semibold">{{ $errors->count() }} {{ \Illuminate\Support\Str::plural('field', $errors->count()) }} need attention. Please fix the highlighted fields and save again.</p>
                    <ul class="mt-2 list-disc pl-5 text-xs space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <div class="sm:col-span-3 rounded-xl border p-4" style="border-color: var(--brand); background: var(--brand-bg);">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Step 1 · PO qualification</div>
                        <h2 class="text-base font-semibold mt-1" style="color: var(--text-primary);">Choose the Nature of PO first</h2>
                        <p class="text-xs mt-1" style="color: var(--text-secondary);">This selection automatically sets the PO category and payment terms before you enter the PO number and other details.</p>
                    </div>
                    @if ($purchaseNature)
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" style="background: var(--surface-1); color: var(--brand);">{{ $purchaseNature }} selected</span>
                    @endif
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                    <label class="flex flex-col gap-1.5 text-sm sm:col-span-3">
                        <span class="font-medium" style="color: var(--text-primary);">Nature of PO <span style="color: var(--status-critical);">*</span></span>
                        <select wire:model.live="purchaseNature" class="rounded-lg border px-3 py-2 text-sm min-h-11" style="border-color: var(--border); background: var(--surface-1);" aria-required="true" aria-describedby="po-nature-help">
                            <option value="">Select nature of PO before continuing</option>
                            @foreach ($purchaseNatures as $nature)<option value="{{ $nature }}">{{ $nature }}</option>@endforeach
                        </select>
                        <span id="po-nature-help" class="text-xs" style="color: var(--text-muted);">Trading, Raw Material, PM Packaging Material, or Others.</span>
                        @error('purchaseNature') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium" style="color: var(--text-primary);">PO category (auto-filled)</span>
                        <input value="{{ $materialType ? ['RM' => 'RM — Raw Material', 'PM' => 'PM — Packaging Material', 'Consumables' => 'Consumables', 'Capex' => 'Capex', 'FG' => 'FG — Finished Goods', 'Other' => 'Other'][$materialType] ?? $materialType : 'Select Nature of PO first' }}" readonly class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);" aria-readonly="true" />
                        <span class="text-xs" style="color: var(--text-muted);">Derived from Nature of PO.</span>
                        @error('materialType') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium" style="color: var(--text-primary);">Payment terms (auto-filled)</span>
                        <input wire:model="paymentTerms" readonly class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);" placeholder="Select Nature of PO first" aria-readonly="true" />
                        <span class="text-xs" style="color: var(--text-muted);">Vendor master term is used when available; otherwise Net 30.</span>
                    </label>
                    <div class="flex items-end text-xs" style="color: var(--text-secondary);">
                        @if ($purchaseNature)
                            <span class="rounded-lg border px-3 py-2.5 w-full" style="border-color: var(--status-good); background: var(--status-good-bg);">Qualification ready. Continue with PO number and vendor.</span>
                        @else
                            <span class="rounded-lg border px-3 py-2.5 w-full" style="border-color: var(--border); background: var(--surface-1);">Select a nature to unlock the derived values.</span>
                        @endif
                    </div>
                </div>
            </div>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">PO Number</span>
                <input wire:model="poNumber" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has('poNumber') ? 'var(--status-critical)' : 'var(--border)' }};" aria-invalid="{{ $errors->has('poNumber') ? 'true' : 'false' }}" placeholder="{{ ($purchaseNature && ($nextPo = app(\App\Services\Tan90\MasterData\NumberSeriesService::class)->peek('Purchase Order: '.$purchaseNature))) ? 'Leave blank for next: '.$nextPo : 'PO RM 2627 0099' }}" />
                @error('poNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Subject</span>
                <input wire:model="subject" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Vendor Name</span>
                <input wire:model.live.debounce.250ms="vendorSearch" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Search vendor / GSTIN" aria-label="Search vendor or GSTIN" />
                @if ($vendorSearch && ! $vendorName)
                    <div class="rounded-lg border overflow-hidden max-h-48 overflow-y-auto" style="border-color: var(--border); background: var(--surface-1);">
                        @forelse ($vendors as $v)
                            <button type="button" wire:click="selectVendor(@js($v->vendor_name))" class="w-full text-left px-3 py-2.5 text-sm transition-colors hover:bg-black/5 focus:outline-none focus-visible:ring-2" style="color: var(--text-primary); --tw-ring-color: var(--brand);">
                                <span class="font-medium">{{ $v->vendor_name }}</span>
                                <span class="block text-xs mt-0.5" style="color: var(--text-muted);">GSTIN {{ $v->gst_number ?: 'Not available' }}</span>
                            </button>
                        @empty
                            <div class="px-3 py-3 text-xs" style="color: var(--text-muted);">No vendor matched this search.</div>
                        @endforelse
                    </div>
                @endif
                @if ($vendorName)
                    <div class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-xs" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">
                        <span><strong>Selected:</strong> {{ $vendorName }}</span>
                        <button type="button" wire:click="clearVendor" class="font-semibold underline">Change</button>
                    </div>
                @endif
                @error('vendorName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                @if ($vendorName && ! $loginVendorNames->contains($vendorName))
                    <p class="text-xs" style="color: var(--status-warning);">This vendor has no portal login yet — they won't be able to see or acknowledge this PO until one is created (Admin → Users).</p>
                @endif
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">PO Date</span>
                <input wire:model="poDate" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Due Date</span>
                <input wire:model="dueDate" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Reference / Requisition</span>
                <input wire:model="referenceNumber" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="PR / project reference" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">{{ $validUptoRequired ? 'PO Valid Upto *' : 'Valid up to' }}</span>
                <input wire:model="validUpto" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('validUpto') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Warehouse / delivery location</span>
                <input wire:model.live.debounce.250ms="warehouseSearch" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Search warehouse or warehouse address" aria-label="Search warehouse or warehouse address" />
                <select wire:model.live="warehouseLocation" aria-label="Warehouse or delivery location" class="rounded-lg border px-3 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="border-color: var(--border); background: var(--surface-1);">
                    <option value="">Select internal warehouse / location / customer</option>
                    @foreach ($deliveryLocations as $location)<option value="{{ $location['value'] }}">{{ $location['label'] }}</option>@endforeach
                </select>
                <span class="text-xs" style="color: var(--text-muted);">Choose a location to load its saved building, street, city, state and PIN into the delivery address below. This does not change the billing address. Review or edit the delivery details before saving.</span>
            </label>
            <label class="flex items-center gap-2 text-sm sm:col-span-2 mt-6">
                <input wire:model="reverseCharge" type="checkbox" class="rounded" />
                <span style="color: var(--text-primary);">Reverse charge applies (tax suppressed on PO)</span>
            </label>
            <div class="sm:col-span-3 rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2);">
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Billing &amp; delivery address</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                    <div class="sm:col-span-2 text-xs font-semibold" style="color: var(--text-secondary);">Billing address (buyer)</div>
                <input wire:model.live="billingBuilding" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has('billingBuilding') ? 'var(--status-critical)' : 'var(--border)' }};" aria-invalid="{{ $errors->has('billingBuilding') ? 'true' : 'false' }}" placeholder="Building / company name" />
                    <input wire:model.live="billingStreet" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has('billingStreet') ? 'var(--status-critical)' : 'var(--border)' }};" aria-invalid="{{ $errors->has('billingStreet') ? 'true' : 'false' }}" placeholder="Street / area" />
                    <input wire:model.live="billingCity" list="billing-cities-list" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has('billingCity') ? 'var(--status-critical)' : 'var(--border)' }};" aria-invalid="{{ $errors->has('billingCity') ? 'true' : 'false' }}" placeholder="Search or type billing city" />
                    <input wire:model.live="billingState" list="india-states-list" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has('billingState') ? 'var(--status-critical)' : 'var(--border)' }};" aria-invalid="{{ $errors->has('billingState') ? 'true' : 'false' }}" placeholder="Search or type billing state" />
                    <input wire:model="billingZip" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has('billingZip') ? 'var(--status-critical)' : 'var(--border)' }};" aria-invalid="{{ $errors->has('billingZip') ? 'true' : 'false' }}" placeholder="Billing PIN" />
                    <div class="sm:col-span-2 flex flex-col lg:flex-row lg:items-start lg:justify-between gap-2 mt-1">
                        <div>
                            <div class="text-xs font-semibold" style="color: var(--text-secondary);">Delivery address (place of supply for GST)</div>
                            <p class="text-xs mt-1" style="color: var(--text-muted);">Search a state to load city suggestions. You may still type a city/state manually; this is only an address helper and will not block the PO.</p>
                            <p class="text-xs mt-1" style="color: var(--status-warning);">Warning: incorrect address data can affect tax classification and delivery documents. Verify before releasing the PO.</p>
                        </div>
                        <div class="flex flex-wrap gap-2" role="group" aria-label="Delivery address source">
                            <button type="button" wire:click="startNewDeliveryAddress" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold transition-colors" style="border-color: {{ $shippingAddressMode === 'new' ? 'var(--brand)' : 'var(--border)' }}; background: {{ $shippingAddressMode === 'new' ? 'var(--brand-bg)' : 'var(--surface-1)' }}; color: {{ $shippingAddressMode === 'new' ? 'var(--brand)' : 'var(--text-primary)' }};">Add new address</button>
                            <button type="button" wire:click="useVendorAddress" @disabled(! $vendorName) class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50" style="border-color: {{ $shippingAddressMode === 'existing' ? 'var(--status-good)' : 'var(--border)' }}; background: {{ $shippingAddressMode === 'existing' ? 'var(--status-good-bg)' : 'var(--surface-1)' }}; color: {{ $shippingAddressMode === 'existing' ? 'var(--status-good)' : 'var(--text-primary)' }};">Use selected vendor address</button>
                        </div>
                    </div>
                    @if ($shippingAddressMode === 'existing')
                        <div class="sm:col-span-2 rounded-lg border px-3 py-2.5 text-xs" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--text-primary);">
                            @if ($selectedVendorAddress)
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                    <span><strong>{{ $selectedVendorAddress->vendor_name }}</strong> · {{ $selectedVendorAddress->address_city ?: 'City not saved' }}, {{ $selectedVendorAddress->address_state ?: 'State not saved' }} {{ $selectedVendorAddress->address_zip }}</span>
                                    <button type="button" wire:click="useVendorAddress" class="font-semibold underline" style="color: var(--status-good);">Load this address</button>
                                </div>
                            @else
                                <span>Select a vendor first to use its saved address.</span>
                            @endif
                        </div>
                    @endif
                    <label class="sm:col-span-2 flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                        <input wire:model.live="sameAsBillingAddress" type="checkbox" class="rounded" />
                        <span>Same as billing address</span>
                    </label>
                    <input wire:model.live="shippingBuilding" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Delivery building / site" />
                    <input wire:model.live="shippingStreet" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Delivery street / area" />
                    <input wire:model.live="shippingCity" list="shipping-cities-list" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Search or type delivery city" />
                    <input wire:model.live="shippingState" list="india-states-list" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Search or type delivery state" />
                    <input wire:model="shippingZip" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Delivery PIN" />
                </div>
                <datalist id="india-states-list">
                    @foreach ($indiaStates as $state)<option value="{{ $state }}"></option>@endforeach
                </datalist>
                <datalist id="billing-cities-list">
                    @foreach ($billingCityOptions as $city)<option value="{{ $city }}"></option>@endforeach
                </datalist>
                <datalist id="shipping-cities-list">
                    @foreach ($shippingCityOptions as $city)<option value="{{ $city }}"></option>@endforeach
                </datalist>
                @foreach (['billingBuilding' => 'Billing building is required.', 'billingStreet' => 'Billing street is required.', 'billingCity' => 'Billing city is required.', 'billingState' => 'Billing state is required.', 'billingZip' => 'Billing PIN is required.'] as $field => $message)
                    @error($field) <span class="block text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                @endforeach
            </div>
            <div class="sm:col-span-3 rounded-lg border p-3" style="border-color: var(--border);">
                <div class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">Additional PO fields</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach ($headerFieldDefinitions as $definition)
                        <label class="flex flex-col gap-1 text-sm"><span class="font-medium" style="color: var(--text-primary);">{{ $definition->label }}</span>
                            @if ($definition->input_type === 'textarea')<textarea wire:model="headerCustomFields.{{ $definition->field_key }}" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>@else<input wire:model="headerCustomFields.{{ $definition->field_key }}" type="{{ $definition->input_type }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />@endif
                        </label>
                    @endforeach
                </div>
            </div>
            @if ($zohoPoFields->isNotEmpty())
                <div class="sm:col-span-3 rounded-lg border p-3" style="border-color: var(--border);">
                    <div class="text-xs font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">Zoho Inventory fields</div>
                    <p class="text-xs mb-2" style="color: var(--text-muted);">Same fields as the Purchase Order in Zoho. Fields marked * are required in Zoho.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ($zohoPoFields as $field)
                            <label class="flex flex-col gap-1 text-sm" wire:key="zoho-po-field-{{ $field->api_name }}">
                                <span class="font-medium" style="color: var(--text-primary);">{{ $field->label }}{{ $field->is_mandatory ? ' *' : '' }}</span>
                                @switch($field->inputType())
                                    @case('select')
                                        <select wire:model="zohoFields.{{ $field->api_name }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                                            <option value="">Select…</option>
                                            @foreach ($field->options ?? [] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                        </select>
                                        @break
                                    @case('textarea')
                                        <textarea wire:model="zohoFields.{{ $field->api_name }}" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
                                        @break
                                    @case('checkbox')
                                        <input wire:model="zohoFields.{{ $field->api_name }}" type="checkbox" class="self-start mt-1">
                                        @break
                                    @default
                                        <input wire:model="zohoFields.{{ $field->api_name }}" type="{{ $field->inputType() }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                @endswitch
                                @error('zohoFields.'.$field->api_name) <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            <label class="sm:col-span-3 flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Notes / terms &amp; conditions</span>
                @if ($termsTemplates->isNotEmpty())
                    <select wire:model.live="termsTemplateId" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1);"><option value="">No template — enter terms manually</option>@foreach ($termsTemplates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select>
                @else
                    <p class="text-xs" style="color: var(--text-muted);">No {{ strtolower($materialType) }} terms template configured; enter terms for this PO.</p>
                @endif
                <textarea wire:model="termsAndConditions" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Supply terms, quality and delivery conditions"></textarea>
            </label>
            <label class="sm:col-span-3 flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">PO attachments (optional, up to 10 files / 10 MB each)</span>
                <input wire:model="attachments" type="file" multiple class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                <span class="text-xs" style="color: var(--text-muted);">Documents remain linked to this PO and can be shared with the vendor workflow.</span>
                @error('attachments') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                @error('attachments.*') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="sm:col-span-3 flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">PDF template</span>
                <select wire:model="pdfTemplate" class="rounded-lg border px-3 py-2 text-sm min-h-11" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);">
                    <option value="standard">Standard A4 — full details</option>
                    <option value="compact">Compact A4 — tighter spacing</option>
                </select>
                @error('pdfTemplate') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <div class="sm:col-span-3 rounded-lg border p-3" style="border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <div class="text-xs font-semibold" style="color: var(--text-muted);">PURCHASE ITEM</div>
                        <p class="text-xs mt-1" style="color: var(--text-secondary);">Add one or more SKUs/products to this same PO.</p>
                    </div>
                    <button type="button" wire:click="addItem" class="rounded-lg border px-3 py-2 text-xs font-semibold shrink-0 hover:bg-black/5 focus:outline-none focus-visible:ring-2" style="border-color: var(--border); color: var(--brand); --tw-ring-color: var(--brand);">+ Add purchase item</button>
                </div>
                <div class="space-y-3">
                    @foreach ($items as $index => $item)
                        <div wire:key="po-item-{{ $index }}" class="rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2);">
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <span class="text-xs font-semibold" style="color: var(--text-secondary);">Item {{ $index + 1 }}</span>
                                @if (count($items) > 1)
                                    <button type="button" wire:click="removeItem({{ $index }})" class="text-xs font-semibold hover:underline" style="color: var(--status-critical);">Remove</button>
                                @endif
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
                                <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                                    <span class="font-medium" style="color: var(--text-primary);">Product / SKU</span>
                                    <input wire:model="items.{{ $index }}.product" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has("items.$index.product") ? 'var(--status-critical)' : 'var(--border)' }};" aria-invalid="{{ $errors->has("items.$index.product") ? 'true' : 'false' }}" placeholder="PCM Raw Compound (TN-1 Grade)" />
                                    @error("items.$index.product") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                </label>
                                <label class="flex flex-col gap-1.5 text-sm">
                                    <span class="font-medium" style="color: var(--text-primary);">HSN / SAC <span class="font-normal" style="color: var(--text-muted);">(optional)</span></span>
                                    <input wire:model="items.{{ $index }}.hsnSac" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Optional — e.g. 39269099" />
                                    <span class="text-xs" style="color: var(--status-warning);">Finance warning: unknown or blank HSN is saved with 0 tax until Finance maps it.</span>
                                    @error("items.$index.hsnSac") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                </label>
                                <label class="flex flex-col gap-1.5 text-sm">
                                    <span class="font-medium" style="color: var(--text-primary);">Quantity</span>
                                    <input wire:model="items.{{ $index }}.quantity" type="number" min="0.001" step="0.001" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has("items.$index.quantity") ? 'var(--status-critical)' : 'var(--border)' }};" aria-invalid="{{ $errors->has("items.$index.quantity") ? 'true' : 'false' }}" />
                                    @error("items.$index.quantity") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                </label>
                                <label class="flex flex-col gap-1.5 text-sm">
                                    <span class="font-medium" style="color: var(--text-primary);">UOM</span>
                                    <select wire:model="items.{{ $index }}.uom" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1);">
                                        @foreach (['KG', 'GRAM', 'PCS', 'BOX', 'METER', 'LITER', 'ROLL', 'SET'] as $unit)<option value="{{ $unit }}">{{ $unit }}</option>@endforeach
                                    </select>
                                    @error("items.$index.uom") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                </label>
                                <label class="flex flex-col gap-1.5 text-sm sm:col-span-4">
                                    <span class="font-medium" style="color: var(--text-primary);">List Price (₹)</span>
                                    <input wire:model="items.{{ $index }}.listPrice" type="number" min="0" step="0.01" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has("items.$index.listPrice") ? 'var(--status-critical)' : 'var(--border)' }};" aria-invalid="{{ $errors->has("items.$index.listPrice") ? 'true' : 'false' }}" />
                                    @error("items.$index.listPrice") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                </label>
                                @foreach ($itemFieldDefinitions as $definition)
                                    <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">{{ $definition->label }}</span>
                                        @if ($definition->input_type === 'textarea')<textarea wire:model="items.{{ $index }}.customFields.{{ $definition->field_key }}" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>@else<input wire:model="items.{{ $index }}.customFields.{{ $definition->field_key }}" type="{{ $definition->input_type }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />@endif
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="sm:col-span-3 flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="cancelPo" class="rounded-lg border px-3.5 py-2 text-sm font-medium" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                <button type="button" wire:click="addPo" class="rounded-lg border px-3.5 py-2 text-sm font-medium" style="border-color: var(--border); color: var(--text-primary);">Save as Draft</button>
                <button type="button" wire:click="saveAndSubmit" wire:loading.attr="disabled" wire:target="saveAndSubmit" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Save PO draft</button>
            </div>
        </div>
    @endif

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-2 py-2.5"></th>
                        <th class="px-4 py-2.5 font-medium">PO Number</th>
                        <th class="px-4 py-2.5 font-medium">Vendor</th>
                        <th class="px-4 py-2.5 font-medium">Status</th>
                        <th class="px-4 py-2.5 font-medium">Grand Total</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $po)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-2 py-2.5">
                                <button wire:click="$set('expanded', {{ $expanded === $po->id ? 'null' : $po->id }})" style="color: var(--text-muted);">{{ $expanded === $po->id ? '▾' : '▸' }}</button>
                            </td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">
                                <button type="button" wire:click="$set('expanded', {{ $expanded === $po->id ? 'null' : $po->id }})" aria-expanded="{{ $expanded === $po->id ? 'true' : 'false' }}" class="min-h-11 inline-flex items-center rounded-lg px-2 -mx-2 text-left transition-colors hover:bg-black/5 focus:outline-none focus-visible:ring-2" style="color: var(--brand); --tw-ring-color: var(--brand);" title="{{ $expanded === $po->id ? 'Hide PO details' : 'Open PO details' }}">
                                    <span class="underline underline-offset-2">{{ $po->po_number }}</span>
                                </button>
                                @unless ($po->isReleased())
                                    <span class="ml-1.5 text-xs font-semibold px-1.5 py-0.5 rounded-full" style="background: var(--status-warning-bg); color: var(--status-warning);">{{ $po->submitted_at ? 'Submitted · awaiting release' : 'Draft' }}</span>
                                @endunless
                            </td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">
                                {{ $po->vendor_name }}
                                @unless ($loginVendorNames->contains($po->vendor_name))
                                    <span class="ml-1 text-xs" style="color: var(--status-critical);" title="No portal login for this vendor name">⚠ no login</span>
                                @endunless
                            </td>
                            <td class="px-4 py-2.5 text-xs font-medium" style="color: var(--text-secondary);">
                                <div>{{ $po->status }}</div>
                                @if ($po->acknowledgement)
                                    <div class="mt-1" style="color: {{ $po->acknowledgement->accepted ? 'var(--status-good)' : 'var(--status-critical)' }};">Vendor {{ $po->acknowledgement->accepted ? 'Accepted' : 'Declined' }} · {{ $po->acknowledgement->acknowledged_at?->format('d M Y, H:i') }}</div>
                                    @if ($po->acknowledgement->remarks)<div class="mt-1 font-normal" style="color: var(--text-muted);">“{{ $po->acknowledgement->remarks }}”</div>@endif
                                @else
                                    <div class="mt-1 font-normal" style="color: var(--status-warning);">Awaiting Vendor</div>
                                @endif
                                @if ($po->isReleased())
                                    <div class="mt-1 font-semibold" style="color: var(--status-good);">Released — visible in vendor portal</div>
                                @else
                                    <div class="mt-1 font-semibold" style="color: var(--status-warning);">Draft — vendor cannot see this yet</div>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">₹{{ number_format($po->grandTotal(), 2) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.po.pdf', $po->po_number) }}" target="_blank" class="font-medium" style="color: var(--brand);">PDF + QR</a>
                                    <a href="{{ route('admin.po.excel', $po->po_number) }}" class="font-medium" style="color: var(--brand);">Download Excel</a>
                                    @unless ($po->isReleased())
                                        <button wire:click="releasePo({{ $po->id }})" wire:loading.attr="disabled" wire:target="releasePo({{ $po->id }})" wire:confirm="Release {{ $po->po_number }} to {{ $po->vendor_name }}? They'll be able to see and acknowledge it in their portal." class="font-medium disabled:opacity-50" style="color: var(--brand);">Release to vendor</button>
                                    @endunless
                                    <button wire:click="deletePo({{ $po->id }})" wire:confirm="Remove PO {{ $po->po_number }}?" style="color: var(--status-critical);">Remove</button>
                                </div>
                            </td>
                        </tr>
                        @if ($expanded === $po->id)
                            <tr style="background: var(--surface-2);">
                                <td></td>
                                <td colspan="5" class="px-4 py-3">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs mb-2">
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Subject</div><div style="color: var(--text-primary);">{{ $po->subject ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">PO Date</div><div style="color: var(--text-primary);">{{ $po->po_date?->format('Y-m-d') ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Due Date</div><div style="color: var(--text-primary);">{{ $po->due_date?->format('Y-m-d') ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Requisition #</div><div style="color: var(--text-primary);">{{ $po->requisition_number ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Nature of PO</div><div style="color: var(--text-primary);">{{ $po->purchase_nature ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">PO category</div><div style="color: var(--text-primary);">{{ $po->material_type ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Payment terms</div><div style="color: var(--text-primary);">{{ $po->payment_terms ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Billing address</div><div style="color: var(--text-primary);">{{ collect([$po->billing_building, $po->billing_street, $po->billing_city, $po->billing_state, $po->billing_zip])->filter()->join(', ') ?: '—' }}</div></div>
                                    </div>
                                    <div class="overflow-x-auto rounded-lg border" style="border-color: var(--border);">
                                        <table class="w-full text-xs">
                                            <thead><tr style="color: var(--text-muted); background: var(--surface-3);"><th class="px-3 py-1.5 text-left">Product</th><th class="px-3 py-1.5 text-left">Qty</th><th class="px-3 py-1.5 text-left">List Price</th></tr></thead>
                                            <tbody>
                                                @foreach ($po->lines as $line)
                                                    <tr style="border-top: 1px solid var(--border);">
                                                        <td class="px-3 py-1.5" style="color: var(--text-primary);">{{ $line->product }}</td>
                                                        <td class="px-3 py-1.5">{{ $line->quantity }} {{ $line->uom ?? 'PCS' }}</td>
                                                        <td class="px-3 py-1.5">₹{{ $line->list_price }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No purchase orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
