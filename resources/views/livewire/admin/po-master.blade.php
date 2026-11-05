<?php

use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCustomFieldDefinition;
use App\Models\PurchaseOrderTermsTemplate;
use App\Models\SkuMaster;
use App\Models\User;
use App\Models\VendorMaster;
use App\Models\ZohoFieldDefinition;
use App\Models\Tan90\MasterData\Customer;
use App\Models\Tan90\MasterData\Location;
use App\Models\Tan90\MasterData\Warehouse;
use App\Services\AuditLogger;
use App\Services\PurchaseOrderTaxService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public bool $adding = false;
    public bool $poPreviewOpen = false;
    public bool $poPdfPreviewOpen = false;
    public string $poPdfPreviewToken = '';
    public ?int $releasePreviewId = null;
    public ?int $expanded = null;
    public string $poLookup = '';
    public string $poStatusFilter = '';
    public string $poVendorFilter = '';
    public string $poLocationFilter = '';
    public string $poDateFrom = '';
    public string $poDateTo = '';
    public array $selectedPoIds = [];
    public bool $allMatchingPos = false;
    public array $excludedPoIds = [];

    public function updatedPoLookup(): void
    {
        $this->resetPoListWindow();
    }

    public function updatedPoStatusFilter(): void
    {
        $this->resetPoListWindow();
    }

    public function updatedPoVendorFilter(): void
    {
        $this->resetPoListWindow();
    }

    public function updatedPoLocationFilter(): void
    {
        $this->resetPoListWindow();
    }

    public function updatedPoDateFrom(): void
    {
        $this->resetPoListWindow();
    }

    public function updatedPoDateTo(): void
    {
        $this->resetPoListWindow();
    }

    private function resetPoListWindow(): void
    {
        $this->resetPage();
        $this->clearPoSelection();
    }

    private function filteredPurchaseOrders(bool $includeStatus = true)
    {
        return PurchaseOrder::query()
            ->when(trim($this->poLookup) !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('po_number', 'like', '%'.trim($this->poLookup).'%')
                ->orWhere('vendor_name', 'like', '%'.trim($this->poLookup).'%')))
            ->when($includeStatus && $this->poStatusFilter !== '', fn ($query) => $query->where('status', $this->poStatusFilter))
            ->when($this->poVendorFilter !== '', fn ($query) => $query->where('vendor_name', $this->poVendorFilter))
            ->when($this->poLocationFilter !== '', fn ($query) => $query->where('location_id', (int) $this->poLocationFilter))
            ->when($this->poDateFrom !== '', fn ($query) => $query->whereDate('po_date', '>=', $this->poDateFrom))
            ->when($this->poDateTo !== '', fn ($query) => $query->whereDate('po_date', '<=', $this->poDateTo));
    }

    public function selectVisiblePos(): void
    {
        $this->allMatchingPos = false;
        $this->excludedPoIds = [];
        $this->selectedPoIds = $this->with()['orders']->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function selectAllMatchingPos(): void
    {
        $this->selectedPoIds = [];
        $this->excludedPoIds = [];
        $this->allMatchingPos = true;
    }

    public function toggleExcludedPo(int $id): void
    {
        $key = (string) $id;
        $this->excludedPoIds = in_array($key, $this->excludedPoIds, true)
            ? array_values(array_diff($this->excludedPoIds, [$key]))
            : [...$this->excludedPoIds, $key];
    }

    public function clearPoSelection(): void
    {
        $this->selectedPoIds = [];
        $this->allMatchingPos = false;
        $this->excludedPoIds = [];
    }

    public function exportFilteredPos()
    {
        $query = $this->filteredPurchaseOrders()->with('lines')->orderByDesc('created_at')->orderByDesc('id');
        if ($this->allMatchingPos) {
            $query->whereNotIn('id', array_map('intval', $this->excludedPoIds));
        } elseif ($this->selectedPoIds !== []) {
            $query->whereIn('id', array_map('intval', $this->selectedPoIds));
        }
        $orders = $query->lazy(200);
        $locations = Location::pluck('name', 'id');
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['PO Number', 'PO Date', 'Due Date', 'Status', 'Vendor', 'Tan90 Location', 'Delivery To', 'Purchase Nature', 'Material Type', 'Item / SKU', 'HSN/SAC', 'Quantity', 'Unit', 'Rate', 'GST %', 'Line Tax', 'Line Discount', 'Line Total', 'PO Subtotal', 'PO Discount', 'PO GST Total', 'Adjustment', 'Grand Total', 'Reference', 'Payment Terms', 'Billing Address', 'Shipping Address', 'Created At', 'Released At', 'Header Custom Fields', 'Line Custom Fields']);
        foreach ($orders as $po) {
            $billing = implode(', ', array_filter([$po->billing_building, $po->billing_street, $po->billing_city, $po->billing_state, $po->billing_zip]));
            $shipping = implode(', ', array_filter([$po->shipping_building, $po->shipping_street, $po->shipping_city, $po->shipping_state, $po->shipping_zip]));
            $lines = $po->lines->isNotEmpty() ? $po->lines : collect([null]);
            foreach ($lines as $line) {
                fputcsv($stream, [
                    $po->po_number,
                    $po->po_date?->format('Y-m-d') ?? '',
                    $po->due_date?->format('Y-m-d') ?? '',
                    $po->status,
                    $po->vendor_name,
                    $locations[$po->location_id] ?? '',
                    $po->warehouse_location,
                    $po->purchase_nature,
                    $po->material_type,
                    $line?->product ?? '',
                    $line?->hsn_sac ?? '',
                    $line?->quantity ?? '',
                    $line?->uom ?? '',
                    $line?->list_price ?? '',
                    $line?->gst_rate ?? '',
                    $line?->tax ?? '',
                    $line?->discount ?? '',
                    $line?->amount() ?? '',
                    $po->subTotal(),
                    $po->discount ?? '',
                    $po->taxTotal(),
                    $po->adjustment ?? '',
                    $po->grandTotal(),
                    $po->reference_number,
                    $po->payment_terms,
                    $billing,
                    $shipping,
                    $po->created_at?->format('Y-m-d H:i:s') ?? '',
                    $po->released_at?->format('Y-m-d H:i:s') ?? '',
                    json_encode($po->header_custom_fields ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($line?->custom_fields ?? [], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
        rewind($stream);
        return response()->streamDownload(function () use ($stream) { fpassthru($stream); fclose($stream); }, 'purchase-orders-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

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
    public string $billingLocationId = '';
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

    // PO Notes (printed under "Notes" on the PO). T&C and Notes fill in from
    // Terms & Notes (vendor's own > Purchase Nature > default) unless typed by hand.
    public string $poNotes = '';
    public string $autoTerms = '';
    public string $autoNotes = '';
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
        ['product' => '', 'hsnSac' => '', 'gstRate' => '', 'quantity' => '', 'uom' => 'PCS', 'listPrice' => '', 'customFields' => []],
    ];
    public array $statuses = ['Created', 'Approved', 'Delivered', 'Cancelled'];
    public array $purchaseNatures = ['Trading', 'Raw Material', 'PM Packaging Material', 'Others'];
    public array $materialTypes = ['RM', 'PM', 'Consumables', 'Capex', 'FG', 'Other'];

    public function mount(): void
    {
        if (request()->routeIs('admin.po.create')) {
            $this->adding = true;
        }
        $defaultBillingLocationId = \App\Support\SiteLocation::current() ?? \App\Support\SiteLocation::defaultId();
        $this->billingLocationId = $defaultBillingLocationId ? (string) $defaultBillingLocationId : '';
        $this->fillBillingAddressFromLocation();
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

    // SKU dropdown shows only items of the PO's type (RM PO -> RM SKUs); words
    // match both Zoho ("Zoho — Raw Material (RM)") and older ERP categories.
    private function skuCategoryPattern(): ?string
    {
        return match ($this->purchaseNature) {
            'Trading' => '%Trading Good%',
            'Raw Material' => '%Raw Material%',
            'PM Packaging Material' => '%Packaging Material%',
            default => $this->materialType === 'FG' ? '%(FG)%' : null, // Consumables / Capex / Other: Zoho has no such category
        };
    }

    // Picking a SKU fills its HSN (from Zoho) and unit, unless typed by hand.
    public function updatedItems(mixed $value, ?string $key = null): void
    {
        if (! preg_match('/^(\d+)\.product$/', (string) $key, $m)) {
            return;
        }
        $sku = SkuMaster::where('sku', $value)->first(['sku', 'zoho_hsn', 'unit', 'tax', 'intra_state_tax_rate', 'inter_state_tax_rate']);
        if (! $sku) {
            return;
        }
        if (filled($sku->zoho_hsn)) {
            $this->items[$m[1]]['hsnSac'] = (string) $sku->zoho_hsn;
        }
        $sameState = strcasecmp(trim($this->billingState), trim($this->shippingState)) === 0;
        $rate = $sameState
            ? ($sku->intra_state_tax_rate ?? $sku->inter_state_tax_rate ?? $sku->tax)
            : ($sku->inter_state_tax_rate ?? $sku->intra_state_tax_rate ?? $sku->tax);
        $this->items[$m[1]]['gstRate'] = $rate === null ? '' : (string) $rate;
        $unit = ['NOS' => 'PCS', 'PC' => 'PCS', 'KGS' => 'KG', 'LTR' => 'LITER', 'L' => 'LITER', 'MTR' => 'METER'][strtoupper((string) $sku->unit)] ?? strtoupper((string) $sku->unit);
        if (in_array($unit, ['KG', 'GRAM', 'PCS', 'BOX', 'METER', 'LITER', 'ROLL', 'SET'], true)) {
            $this->items[$m[1]]['uom'] = $unit;
        }
        $this->resetValidation(["items.{$m[1]}.product", "items.{$m[1]}.hsnSac"]);
    }

    public function addItem(): void
    {
        $this->items[] = ['product' => '', 'hsnSac' => '', 'gstRate' => '', 'quantity' => '', 'uom' => 'PCS', 'listPrice' => '', 'customFields' => []];
    }

    public function openForm(): void
    {
        $this->adding = true;
    }

    // True while the PO number field holds the auto-suggested number (not typed).
    public bool $poNumberAuto = false;

    public function updatedPoNumber(): void
    {
        $this->poNumberAuto = false;
    }

    // Choosing the PO type fills the next number (POTG26270147); typing your own keeps it.
    private function suggestPoNumber(): void
    {
        if (trim($this->poNumber) !== '' && ! $this->poNumberAuto) {
            return;
        }
        $next = app(\App\Services\PoNumberService::class)->next($this->purchaseNature, $this->materialType);
        $this->poNumber = (string) $next;
        $this->poNumberAuto = $next !== null;
        $this->resetValidation('poNumber');
    }

    public function updatedPurchaseNature(): void
    {
        $this->applyNatureDefaults();
        $this->suggestPoNumber();
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
        $this->suggestPoNumber();

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
        // No address saved yet: ask Zoho once (read-only) before filling the form.
        if (blank($vendor->address_city) && ! $vendor->zoho_address_checked_at) {
            app(\App\Services\Zoho\ContactAddressService::class)->fillVendor($vendor);
        }

        $this->vendorName = $vendorName;
        $this->vendorSearch = $vendorName;
        // The supplier is the payee, not the buyer: Tan90 billing details
        // come from the selected Tan90 location. Vendor address is available
        // separately when the team chooses it for delivery.
        $this->paymentTerms = '';
        $this->applyNatureDefaults();
        if ($this->shippingAddressMode === 'existing') {
            $this->useVendorAddress();
        }
        $this->applyTermsAndNotes();
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
        if ($location && \App\Support\SiteLocation::visibleLocations()->contains('id', (int) $location->id)) {
            $this->billingLocationId = (string) $location->id;
            $this->fillBillingAddressFromLocation();
        }
        if ($customer && blank($customer->address_zip) && ! $customer->zoho_address_checked_at) {
            app(\App\Services\Zoho\ContactAddressService::class)->fillCustomer($customer);
        }

        $this->sameAsBillingAddress = false;
        $this->shippingAddressMode = 'new';
        $this->shippingBuilding = (string) ($warehouse?->name ?? $location?->name ?? ($customer?->address_building ?: $customer?->name) ?? '');
        $this->shippingStreet = (string) ($location?->address ?? $customer?->address_street ?? '');
        $this->shippingCity = (string) ($location?->city ?? $customer?->city ?? '');
        $this->shippingState = (string) ($location?->state ?? $customer?->state ?? '');
        $this->shippingZip = (string) ($location?->pincode ?? $customer?->address_zip ?? '');
        $this->resetValidation(['shippingBuilding', 'shippingStreet', 'shippingCity', 'shippingState', 'shippingZip']);
    }

    public function updatedBillingLocationId(string $value): void
    {
        $location = $this->visibleBillingLocations()->firstWhere('id', (int) $value);
        if (! $location) {
            $this->billingLocationId = '';
            $this->addError('billingLocationId', 'Choose a Tan90 billing location within your access scope.');

            return;
        }

        $this->fillBillingAddressFromLocation();
        $this->resetValidation(['billingLocationId', 'billingBuilding', 'billingStreet', 'billingCity', 'billingState', 'billingZip']);
    }

    private function fillBillingAddressFromLocation(): void
    {
        $location = $this->visibleBillingLocations()->firstWhere('id', (int) $this->billingLocationId);
        if (! $location) {
            return;
        }

        $this->billingBuilding = (string) $location->name;
        $this->billingStreet = (string) ($location->address ?? '');
        $this->billingCity = (string) ($location->city ?? '');
        $this->billingState = (string) ($location->state ?? '');
        $this->billingZip = (string) ($location->pincode ?? '');
        $this->billingCountry = 'India';
    }

    private function visibleBillingLocations()
    {
        return Location::query()
            ->whereIn('type', ['Manufacturing', 'Warehouse'])
            ->when(\App\Support\SiteLocation::current(), fn ($query, $siteId) => $query->whereKey($siteId))
            ->orderBy('name')
            ->get();
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

    // Fill T&C / Notes from Terms & Notes; never overwrite what someone typed.
    private function applyTermsAndNotes(): void
    {
        $vendor = $this->vendorName ? VendorMaster::where('vendor_name', $this->vendorName)->first() : null;
        $template = \App\Models\TermsTemplate::resolve('vendor', $vendor?->terms_template_id, ($this->zohoFields['cf_purchase_nature'] ?? '') ?: null);
        if (! $template) {
            return;
        }
        if (trim($this->termsAndConditions) === '' || $this->termsAndConditions === $this->autoTerms) {
            $this->termsAndConditions = $this->autoTerms = (string) $template->terms;
        }
        if (trim($this->poNotes) === '' || $this->poNotes === $this->autoNotes) {
            $this->poNotes = $this->autoNotes = (string) $template->notes;
        }
    }

    public function updated($property, $value): void
    {
        if ($property === 'zohoFields.cf_purchase_nature' || $property === 'zohoFields') {
            $this->applyTermsAndNotes();
        }
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

    public function openPoPreview(): void
    {
        $this->applyNatureDefaults();
        $this->fillPoNumberFromSeries();
        $this->poPreviewOpen = true;
    }

    public function closePoPreview(): void
    {
        $this->poPreviewOpen = false;
        $this->poPdfPreviewOpen = false;
        $this->poPdfPreviewToken = '';
    }

    public function showPoPdfView(): void
    {
        abort_unless($this->poPreviewOpen && $this->adding, 409);

        $this->applyNatureDefaults();
        $this->fillPoNumberFromSeries();

        $token = (string) Str::uuid();
        $fields = [
            'po_number' => $this->poNumber,
            'subject' => $this->subject,
            'vendor_name' => $this->vendorName,
            'status' => 'Created',
            'po_date' => $this->poDate ?: null,
            'due_date' => $this->dueDate ?: null,
            'reference_number' => $this->referenceNumber ?: null,
            'payment_terms' => $this->paymentTerms ?: null,
            'valid_upto' => $this->validUpto ?: null,
            'purchase_nature' => $this->purchaseNature,
            'material_type' => $this->materialType,
            'pdf_template' => $this->pdfTemplate,
            'shipment_preference' => $this->shipmentPreference ?: null,
            'reverse_charge' => $this->reverseCharge,
            'billing_country' => $this->billingCountry ?: 'India',
            'billing_building' => $this->billingBuilding,
            'billing_street' => $this->billingStreet,
            'billing_city' => $this->billingCity,
            'billing_state' => $this->billingState,
            'billing_zip' => $this->billingZip,
            'shipping_country' => $this->shippingCountry ?: 'India',
            'shipping_building' => $this->shippingBuilding,
            'shipping_street' => $this->shippingStreet,
            'shipping_city' => $this->shippingCity,
            'shipping_state' => $this->shippingState,
            'shipping_zip' => $this->shippingZip,
            'warehouse_location' => $this->warehouseLocation,
            'terms_and_conditions' => $this->termsAndConditions,
            'description' => $this->poNotes,
            'header_custom_fields' => collect($this->headerCustomFields)->filter(fn ($value) => $value !== '' && $value !== null)->all(),
            'zoho_custom_fields' => collect($this->zohoFields)->filter(fn ($value) => $value !== '' && $value !== null)->all(),
            'lines' => collect($this->items)->map(fn (array $item) => [
                'product' => trim((string) ($item['product'] ?? '')),
                'hsn_sac' => $item['hsnSac'] ?? null,
                'quantity' => $item['quantity'] ?? 0,
                'uom' => $item['uom'] ?? 'PCS',
                'list_price' => $item['listPrice'] ?? 0,
                'gst_rate' => $item['gstRate'] ?? null,
                'custom_fields' => collect($item['customFields'] ?? [])->filter(fn ($value) => $value !== '' && $value !== null)->all(),
            ])->filter(fn (array $item) => $item['product'] !== '' || (float) $item['quantity'] > 0)->values()->all(),
        ];

        // The token is unguessable, short-lived, and tied to the signed-in user.
        // Only the current form snapshot is cached; no PO or inventory record is created.
        \Illuminate\Support\Facades\Cache::put(
            'po-pdf-preview:'.auth()->id().':'.$token,
            $fields,
            now()->addMinutes(10),
        );

        $this->poPdfPreviewToken = $token;
        $this->poPdfPreviewOpen = true;
    }

    public function showPoPdfSummary(): void
    {
        $this->poPdfPreviewOpen = false;
    }

    public function openReleasePreview(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        abort_if($po->isReleased(), 409, 'This PO is already released.');
        $this->releasePreviewId = $po->id;
    }

    public function closeReleasePreview(): void
    {
        $this->releasePreviewId = null;
    }

    public function confirmReleasePo(): void
    {
        abort_unless($this->releasePreviewId, 404);
        $id = $this->releasePreviewId;
        $this->releasePreviewId = null;
        $this->releasePo($id);
    }

    // Blank or auto-filled PO Number = next number in the Zoho format
    // (POTG26270147, restarts each April), re-checked at save time in case
    // someone else saved that number meanwhile. A typed number is used as before.
    private function fillPoNumberFromSeries(): void
    {
        if ((trim($this->poNumber) !== '' && ! $this->poNumberAuto) || $this->purchaseNature === '') {
            return;
        }
        $this->poNumber = (string) app(\App\Services\PoNumberService::class)->next($this->purchaseNature, $this->materialType);
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
            'billingLocationId' => ['nullable', 'integer', Rule::in($this->visibleBillingLocations()->pluck('id')->all())],
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
            'items.*.gstRate' => ['nullable', 'numeric', 'between:0,100'],
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
            'location_id' => $this->billingLocationId !== '' ? (int) $this->billingLocationId : null,
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
            'description' => $this->poNotes ?: null,
            'header_custom_fields' => $headerValues,
            'zoho_custom_fields' => array_filter($this->zohoFields, fn ($value) => $value !== '' && $value !== null) ?: null,
        ]);

        foreach ($this->items as $item) {
            $rate = filled($item['gstRate'] ?? null) ? (float) $item['gstRate'] : ($taxService->rateForHsn($item['hsnSac'] ?? null) ?? 0.0);
            $lineTax = $taxService->breakdown(
                (float) $item['quantity'] * (float) $item['listPrice'],
                0,
                (float) $rate,
                $taxService->placeOfSupply($this->shippingState, $this->billingState),
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
        if ($releaseToVendor) {
            app(\App\Services\OperationalEventMailer::class)->notify(
                "Purchase order {$po->po_number} released",
                "{$po->po_number} for {$po->vendor_name} was released to the vendor. It is ready for the inward receiving workflow.",
                $po->location_id ? (int) $po->location_id : null,
                [Role::StoreManager, Role::Finance],
                '/admin/po',
            );
        }
        $this->poPreviewOpen = false;
        $this->poPdfPreviewOpen = false;
        $this->poPdfPreviewToken = '';
        $this->reset(['poNumber', 'poNumberAuto', 'subject', 'vendorName', 'poDate', 'dueDate', 'referenceNumber', 'paymentTerms', 'validUpto', 'purchaseNature', 'materialType', 'pdfTemplate', 'termsTemplateId', 'shipmentPreference', 'billingBuilding', 'billingStreet', 'billingCity', 'billingState', 'billingZip', 'billingLocationId', 'shippingBuilding', 'shippingStreet', 'shippingCity', 'shippingState', 'shippingZip', 'warehouseLocation', 'warehouseSearch', 'termsAndConditions', 'poNotes', 'autoTerms', 'autoNotes', 'headerCustomFields', 'zohoFields', 'reverseCharge', 'attachments', 'vendorSearch', 'adding', 'shippingAddressMode', 'sameAsBillingAddress']);
        $this->billingCountry = $this->shippingCountry = 'India';
        $this->billingLocationId = (string) (\App\Support\SiteLocation::current() ?? \App\Support\SiteLocation::defaultId() ?? '');
        $this->fillBillingAddressFromLocation();
        $this->purchaseNature = '';
        $this->materialType = '';
        $this->pdfTemplate = 'standard';
        $this->items = [['product' => '', 'hsnSac' => '', 'gstRate' => '', 'quantity' => '', 'uom' => 'PCS', 'listPrice' => '', 'customFields' => []]];
        $this->status = 'Created';
        if ($releaseToVendor) {
            session()->flash('po-released', "{$po->po_number} released to {$po->vendor_name}; it is now available in the vendor portal for acknowledgement.");
        } else {
            session()->flash('po-saved', $submit ? 'PO submitted for release. Use Release to vendor when it is approved.' : 'PO saved as a draft.');
        }
        session()->flash('po-created', $po->po_number); // "Download PDF" button above the list
        if (request()->routeIs('admin.po.create')) {
            $this->redirectRoute('admin.po', navigate: true);
        }
    }

    public function cancelPo(): void
    {
        $this->poPreviewOpen = false;
        $this->poPdfPreviewOpen = false;
        $this->poPdfPreviewToken = '';
        $this->reset(['adding', 'poNumber', 'poNumberAuto', 'subject', 'vendorName', 'poDate', 'dueDate', 'referenceNumber', 'paymentTerms', 'validUpto', 'purchaseNature', 'billingBuilding', 'billingStreet', 'billingCity', 'billingState', 'billingZip', 'billingLocationId', 'shippingBuilding', 'shippingStreet', 'shippingCity', 'shippingState', 'shippingZip', 'warehouseLocation', 'warehouseSearch', 'termsAndConditions', 'poNotes', 'autoTerms', 'autoNotes', 'headerCustomFields', 'zohoFields', 'attachments', 'vendorSearch', 'shippingAddressMode', 'sameAsBillingAddress']);
        $this->items = [['product' => '', 'hsnSac' => '', 'gstRate' => '', 'quantity' => '', 'uom' => 'PCS', 'listPrice' => '', 'customFields' => []]];
        $this->billingCountry = $this->shippingCountry = 'India';
        $this->billingLocationId = (string) (\App\Support\SiteLocation::current() ?? \App\Support\SiteLocation::defaultId() ?? '');
        $this->fillBillingAddressFromLocation();
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
    // Vendor not available to accept in the portal: Store Manager accepts on
    // the vendor's behalf. Optional details are retained when supplied.
    public ?int $bypassingPoId = null;

    public string $bypassReason = '';

    // Bypass = Store Manager does the vendor's part: accept the PO AND submit
    // the dispatch documents the vendor would upload (invoice / e-way bill /
    // LR-POD, quantities, vehicle). That makes the same VendorSubmission the
    // vendor portal makes, so the Guard fetches it at the gate as usual.
    public string $bypassInvoiceNumber = '';

    public string $bypassInvoiceDate = '';

    public string $bypassVehicle = '';

    public string $bypassArrival = '';

    public array $bypassLines = [];

    public $bypassInvoiceFile;

    public $bypassEwayFile;

    public $bypassLrFile;

    public function openBypass(int $id): void
    {
        $po = PurchaseOrder::with('lines')->findOrFail($id);
        $this->reset(['bypassReason', 'bypassInvoiceNumber', 'bypassInvoiceDate', 'bypassVehicle', 'bypassArrival', 'bypassInvoiceFile', 'bypassEwayFile', 'bypassLrFile']);
        $this->bypassingPoId = $po->id;
        $this->bypassInvoiceDate = today()->toDateString();
        $this->bypassLines = $po->lines->mapWithKeys(fn ($line) => [$line->id => (float) $line->quantity])->all();
        $this->resetValidation();
    }

    public function bypassVendorAcceptance(): void
    {
        $po = PurchaseOrder::with(['acknowledgement', 'lines'])->findOrFail($this->bypassingPoId);
        abort_unless(auth()->user()?->isAdmin() || auth()->user()?->holdsRole(Role::StoreManager), 403);
        $this->validate([
            'bypassReason' => ['nullable', 'string', 'max:500'],
            'bypassInvoiceNumber' => ['nullable', 'string', 'max:255'],
            'bypassInvoiceDate' => ['nullable', 'date'],
            'bypassVehicle' => ['nullable', 'string', 'max:255'],
            'bypassArrival' => ['nullable', 'date'],
            'bypassLines.*' => ['nullable', 'numeric', 'min:0'],
            'bypassInvoiceFile' => ['nullable', 'file', 'max:5120'],
            'bypassEwayFile' => ['nullable', 'file', 'max:5120'],
            'bypassLrFile' => ['nullable', 'file', 'max:5120'],
        ], [], [
            'bypassInvoiceNumber' => 'vendor invoice number', 'bypassInvoiceDate' => 'invoice date', 'bypassVehicle' => 'vehicle number',
            'bypassArrival' => 'expected arrival', 'bypassInvoiceFile' => 'invoice file', 'bypassEwayFile' => 'e-way bill', 'bypassLrFile' => 'LR / POD',
        ]);
        if (! $po->isReleased()) {
            $this->addError('bypassReason', 'Release the PO first.');

            return;
        }
        $invoiceNumber = trim($this->bypassInvoiceNumber) ?: null;
        if ($invoiceNumber && \App\Models\VendorSubmission::withoutSiteScope()->where('vendor_name', $po->vendor_name)->where('invoice_number', $invoiceNumber)->exists()) {
            $this->addError('bypassInvoiceNumber', 'This invoice number is already submitted for this vendor.');

            return;
        }
        $lines = collect($this->bypassLines)->map(fn ($q) => (float) $q)->filter(fn ($q) => $q > 0);
        foreach ($lines as $lineId => $qty) {
            $line = $po->lines->firstWhere('id', (int) $lineId);
            if (! $line || $qty > (float) $line->quantity) {
                $this->addError("bypassLines.{$lineId}", 'More than ordered on this line.');

                return;
            }
        }
        $reason = trim($this->bypassReason) ?: 'Manual acceptance by Store Manager.';
        $paths = [
            'invoice' => $this->bypassInvoiceFile?->store('documents'),
            'eway' => $this->bypassEwayFile?->store('documents'),
            'lr' => $this->bypassLrFile?->store('documents'),
        ];
        [$ack, $submission] = \Illuminate\Support\Facades\DB::transaction(function () use ($po, $reason, $lines, $paths, $invoiceNumber) {
            $ack = \App\Models\PurchaseOrderAcknowledgement::updateOrCreate(['po_number' => $po->po_number], [
                'vendor_name' => $po->vendor_name, 'accepted' => true,
                'remarks' => 'Accepted by '.auth()->user()->name.' on the vendor\'s behalf (bypass): '.$reason,
                'acknowledged_by' => auth()->id(), 'acknowledged_at' => now(), 'production_started_at' => now(),
            ]);
            $allocations = $lines->mapWithKeys(fn ($q, $id) => [(int) $id => $q])->all();
            $submission = \App\Models\VendorSubmission::create([
                'po_number' => $po->po_number, 'vendor_name' => $po->vendor_name,
                'invoice_number' => $invoiceNumber, 'invoice_date' => $this->bypassInvoiceDate ?: null, 'invoice_due_date' => null,
                'invoice_qty' => $lines->isEmpty() ? null : (int) round($lines->sum()),
                'invoice_amount' => $lines->isEmpty() ? null : round(app(\App\Services\PurchaseOrderAmountCalculator::class)->amounts($po, $allocations)['total'], 2),
                'material' => $po->lines->whereIn('id', $lines->keys()->map(fn ($k) => (int) $k)->all())->pluck('product')->unique()->implode(' + ') ?: null,
                'line_quantities' => $lines->all(),
                'expected_arrival_at' => $this->bypassArrival ?: null, 'vehicle_number' => trim($this->bypassVehicle) ?: null,
                'has_invoice' => (bool) $paths['invoice'], 'invoice_document_path' => $paths['invoice'],
                'has_eway_bill' => (bool) $paths['eway'], 'eway_bill_document_path' => $paths['eway'],
                'has_lr_pod' => (bool) $paths['lr'], 'lr_pod_document_path' => $paths['lr'],
                'status' => 'submitted', 'note' => 'Submitted by '.auth()->user()->name.' for the vendor (bypass): '.$reason,
                'production_started_at' => now(), 'dispatched_at' => now(), 'tat_due_at' => $po->due_date?->copy()->endOfDay(),
            ]);

            return [$ack, $submission];
        });
        AuditLogger::log('PO vendor acceptance bypassed', "{$po->po_number} · {$po->vendor_name} · {$reason}", $ack);
        AuditLogger::log('Vendor submission created', "{$submission->po_number} · {$submission->invoice_number} (by Store Manager, bypass)", $submission);
        $submissionSummary = $submission->invoice_number ? "invoice {$submission->invoice_number}" : 'manual dispatch details';
        session()->flash('success', "{$po->po_number} accepted for the vendor and {$submissionSummary} recorded. The Guard can now fetch it at the gate.");
        $this->reset(['bypassingPoId', 'bypassReason', 'bypassInvoiceNumber', 'bypassInvoiceDate', 'bypassVehicle', 'bypassArrival', 'bypassLines', 'bypassInvoiceFile', 'bypassEwayFile', 'bypassLrFile']);
    }

    // Virtual GRN: Trading goods the vendor ships straight to the customer.
    public ?int $virtualPoId = null;

    public string $virtualCustomer = '';

    public string $virtualInvoice = '';

    public string $virtualInvoiceDate = '';

    public array $virtualLines = [];

    public function openVirtual(int $id): void
    {
        $po = PurchaseOrder::with('lines')->findOrFail($id);
        $this->virtualPoId = $po->id;
        $this->virtualCustomer = '';
        $this->virtualInvoice = '';
        $this->virtualInvoiceDate = now()->toDateString();
        $this->virtualLines = $po->lines->mapWithKeys(fn ($l) => [$l->id => (float) $l->quantity])->all();
        $this->resetValidation();
    }

    public function saveVirtual(): void
    {
        abort_unless(auth()->user()?->isAdmin() || auth()->user()?->holdsRole(Role::StoreManager), 403);
        $po = PurchaseOrder::findOrFail($this->virtualPoId);
        $this->validate(['virtualInvoiceDate' => ['required', 'date'], 'virtualLines.*' => ['nullable', 'numeric', 'min:0']], [], ['virtualInvoiceDate' => 'invoice date']);
        $gate = app(\App\Services\VirtualGrnService::class)->create($po, array_map('floatval', $this->virtualLines), $this->virtualCustomer, $this->virtualInvoice, $this->virtualInvoiceDate, auth()->user());
        session()->flash('success', "Virtual GRN {$gate->gate_no} done for {$po->po_number}: stock received and dispatched to ".trim($this->virtualCustomer).' in one go.');
        $this->reset(['virtualPoId', 'virtualCustomer', 'virtualInvoice', 'virtualInvoiceDate', 'virtualLines']);
    }

    // Edit the billing address of a saved PO (Zoho-pulled POs often have none,
    // which blocks Release to vendor). Prefill from the PO's Tan90 location.
    public ?int $addressPoId = null;

    public array $addressForm = [];

    public function openAddressEdit(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);
        $location = Location::find($po->location_id);
        $this->addressPoId = $po->id;
        $this->addressForm = [
            'building' => (string) ($po->billing_building ?: $location?->name ?: ''),
            'street' => (string) ($po->billing_street ?: $location?->address ?: ''),
            'city' => (string) ($po->billing_city ?: $location?->city ?: ''),
            'state' => (string) ($po->billing_state ?: $location?->state ?: ''),
            'zip' => (string) ($po->billing_zip ?: $location?->pincode ?: ''),
        ];
        $this->resetValidation();
    }

    public function saveAddress(bool $release = false): void
    {
        $po = PurchaseOrder::findOrFail($this->addressPoId);
        $this->validate([
            'addressForm.building' => ['required', 'string', 'max:255'],
            'addressForm.street' => ['required', 'string', 'max:255'],
            'addressForm.city' => ['required', 'string', 'max:100'],
            'addressForm.state' => ['required', 'string', 'max:100'],
            'addressForm.zip' => ['required', 'string', 'max:20'],
        ], [], ['addressForm.building' => 'building / name', 'addressForm.street' => 'street', 'addressForm.city' => 'city', 'addressForm.state' => 'state', 'addressForm.zip' => 'PIN code']);

        $po->update([
            'billing_building' => trim($this->addressForm['building']),
            'billing_street' => trim($this->addressForm['street']),
            'billing_city' => trim($this->addressForm['city']),
            'billing_state' => trim($this->addressForm['state']),
            'billing_zip' => trim($this->addressForm['zip']),
        ]);
        AuditLogger::log('Purchase Order billing address edited', "{$po->po_number} · {$po->vendor_name}", $po);
        $this->reset(['addressPoId', 'addressForm']);

        if ($release) {
            $this->releasePo($po->id);
        } else {
            session()->flash('po-released', "{$po->po_number}: billing address saved.");
        }
    }

    public function releasePo(int $id): void
    {
        $po = PurchaseOrder::with('lines')->findOrFail($id);

        if ($po->isReleased()) {
            return;
        }

        if (! $po->billing_building || ! $po->billing_street || ! $po->billing_city || ! $po->billing_state || ! $po->billing_zip) {
            // Open the address box on this PO's row so it can be fixed right here.
            $this->openAddressEdit($po->id);
            $this->addError('addressEdit', 'This PO has no complete billing address. Fill it below, then Save & release.');

            return;
        }
        // Delivery-state/GST-code validation is intentionally bypassed for
        // now. Tax breakdown remains fail-safe for an unmapped place of supply.
        $taxService = app(PurchaseOrderTaxService::class);
        foreach ($po->lines as $line) {
            $rate = $line->gst_rate ?? $taxService->rateForHsn($line->hsn_sac) ?? 0.0;
            $breakdown = $taxService->breakdown((float) $line->quantity * (float) $line->list_price, (float) $line->discount, (float) $rate, $taxService->placeOfSupply($po->shipping_state, $po->billing_state), (bool) $po->reverse_charge);
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
        app(\App\Services\OperationalEventMailer::class)->notify(
            "Purchase order {$po->po_number} released",
            "{$po->po_number} for {$po->vendor_name} was released to the vendor. It is ready for the inward receiving workflow.",
            $po->location_id ? (int) $po->location_id : null,
            [Role::StoreManager, Role::Finance],
            '/admin/po',
        );
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

        // "Name · City, State"; with no address yet just the name (picking it fetches it from Zoho).
        $place = fn (...$parts) => ($p = collect($parts)->filter()->implode(', ')) ? ' · '.$p : '';
        $search = trim($this->warehouseSearch);
        $site = \App\Support\SiteLocation::current(); // a site's staff deliver to their own site's warehouses
        $deliveryLocations = Warehouse::with('location')->when($site, fn ($q) => $q->where('tan90_location_id', $site))->orderBy('name')->get()->toBase()->map(fn ($warehouse) => [
            'value' => $warehouse->name,
            'label' => $warehouse->name.$place($warehouse->location?->city, $warehouse->location?->state),
        ])->merge(Location::when($site, fn ($q) => $q->whereKey($site))->orderBy('name')->get()->map(fn ($location) => [
            'value' => $location->name,
            'label' => $location->name.$place($location->city, $location->state),
        ]))->merge(Customer::withAddress()->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')->limit(50)->get()->map(fn ($customer) => [ // 3,000+ customers: only the ones matching the search
                'value' => $customer->name,
                'label' => $customer->name.' · customer'.$place($customer->city, $customer->state),
            ]))->unique('value')->values();

        if (trim($this->warehouseSearch) !== '') {
            $needle = Str::lower(trim($this->warehouseSearch));
            $deliveryLocations = $deliveryLocations->filter(fn ($location) => Str::contains(Str::lower($location['value'].' '.$location['label']), $needle))->values();
        }

        $skuOptions = $this->purchaseNature === '' ? collect() : SkuMaster::where('active', true)
            ->when($this->skuCategoryPattern(), fn ($q, $pattern) => $q->where('category', 'like', $pattern))
            ->orderBy('sku')->get(['sku', 'category', 'zoho_hsn', 'intra_state_tax_rate', 'inter_state_tax_rate', 'tax']);
        $inventoryLabels = \App\Models\Inventory\InventoryItem::whereIn('sku', $skuOptions->pluck('sku'))
            ->get(['sku', 'code', 'name', 'masked_name', 'alternative_name'])->keyBy('sku');
        $skuOptions->each(function ($sku) use ($inventoryLabels): void {
            $category = match (true) {
                str_contains((string) $sku->category, '(RM)') => 'RM',
                str_contains((string) $sku->category, '(PM)') => 'PM',
                str_contains((string) $sku->category, '(FG)') => 'FG',
                str_contains((string) $sku->category, 'Trading Good') => 'Trading',
                default => null,
            };
            $label = $inventoryLabels->get($sku->sku)?->safeDisplayName() ?: $sku->sku;
            $sku->display_label = $category ? "{$label} · {$category}" : $label;
        });

        $poQuery = $this->filteredPurchaseOrders();
        $poStatusCounts = $this->filteredPurchaseOrders(false)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $vendorOptions = PurchaseOrder::query()->whereNotNull('vendor_name')->where('vendor_name', '!=', '')
            ->distinct()->orderBy('vendor_name')->pluck('vendor_name');

        return [
            // Keep the interactive list bounded; export queries every matching PO.
            'orders' => (clone $poQuery)->with(['lines', 'acknowledgement'])->orderByDesc('created_at')->orderByDesc('id')->paginate(200),
            // Nothing until Nature of PO is chosen (the list depends on it).
            'skuOptions' => $skuOptions,
            'bypassPo' => $this->bypassingPoId ? PurchaseOrder::with('lines')->find($this->bypassingPoId) : null,
            'virtualPo' => $this->virtualPoId ? PurchaseOrder::with('lines')->find($this->virtualPoId) : null,
            'releasePreviewPo' => $this->releasePreviewId ? PurchaseOrder::with('lines')->find($this->releasePreviewId) : null,
            'previewVendor' => $this->vendorName ? VendorMaster::where('vendor_name', $this->vendorName)->first() : null,
            'previewLines' => collect($this->items)->map(function (array $item) {
                $rate = filled($item['gstRate'] ?? null)
                    ? (float) $item['gstRate']
                    : (app(PurchaseOrderTaxService::class)->rateForHsn($item['hsnSac'] ?? null) ?? 0.0);
                $tax = app(PurchaseOrderTaxService::class)->breakdown(
                    (float) ($item['quantity'] ?? 0) * (float) ($item['listPrice'] ?? 0),
                    0,
                    $rate,
                    app(PurchaseOrderTaxService::class)->placeOfSupply($this->shippingState, $this->billingState),
                    $this->reverseCharge,
                );
                return [...$item, 'preview_rate' => $rate, 'preview_tax' => $tax['cgst'] + $tax['sgst'] + $tax['igst'], 'preview_total' => $tax['taxable'] + $tax['cgst'] + $tax['sgst'] + $tax['igst']];
            }),
            'ordersTotal' => (clone $poQuery)->count(),
            'poStatusCounts' => $poStatusCounts,
            'vendorOptions' => $vendorOptions,
            'poLocationOptions' => $this->visibleBillingLocations(),
            'vendors' => VendorMaster::withAddress()->when($this->vendorSearch, fn ($query) => $query->where(function ($q) {
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
            'billingLocations' => $this->visibleBillingLocations(),
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
                    <a href="{{ route('admin.po.create') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Add PO</a>
                @endif
            </div>
        </div>
    </section>

    @if (session('po-saved'))
        <div class="rounded-lg border px-4 py-3 mb-4 text-sm" role="status" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">{{ session('po-saved') }}</div>
    @endif
    @if (session('po-created'))
        <div class="rounded-lg border px-4 py-3 mb-4 text-sm flex flex-wrap items-center gap-3" role="status" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">
            <span>{{ session('po-created') }} created.</span>
            <a href="{{ route('admin.po.pdf', session('po-created')) }}" target="_blank" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-white" style="background: var(--brand);">Download PDF</a>
        </div>
    @endif
    @if (session('po-settings-saved'))
        <div class="rounded-lg border px-4 py-3 mb-4 text-sm" role="status" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">{{ session('po-settings-saved') }}</div>
    @endif
    @if (session('success'))
        <div class="rounded-lg border px-4 py-3 mb-4 text-sm" role="status" aria-live="polite" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">{{ session('success') }}</div>
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
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-bold" style="color:var(--text-primary);">Create purchase order</h1>
            <a href="{{ route('admin.po') }}" wire:navigate class="text-sm font-semibold" style="color:var(--brand);">← Back to purchase orders</a>
        </div>
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
                <input wire:model="poNumber" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has('poNumber') ? 'var(--status-critical)' : 'var(--border)' }};" aria-invalid="{{ $errors->has('poNumber') ? 'true' : 'false' }}" placeholder="Choose Nature of PO - the next number fills in" />
                @if ($poNumberAuto) <span class="text-xs" style="color: var(--text-muted);">Next number for this PO type. You can type your own instead.</span> @endif
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
                <span class="text-xs" style="color: var(--text-muted);">Choose a Tan90 warehouse or location to load its address below. Internal locations also select the matching Tan90 billing address; customers only change delivery details. Review or edit both before saving.</span>
            </label>
            <label class="flex items-center gap-2 text-sm sm:col-span-2 mt-6">
                <input wire:model="reverseCharge" type="checkbox" class="rounded" />
                <span style="color: var(--text-primary);">Reverse charge applies (tax suppressed on PO)</span>
            </label>
            <div class="sm:col-span-3 rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2);">
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Billing &amp; delivery address</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                    <div class="sm:col-span-2 text-xs font-semibold" style="color: var(--text-secondary);">Billing address (buyer)</div>
                    <label class="sm:col-span-2 flex flex-col gap-1.5 text-sm">
                        <span class="font-medium" style="color: var(--text-primary);">Tan90 billing location</span>
                        <select wire:model.live="billingLocationId" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1);">
                            <option value="">Select Tan90 location…</option>
                            @foreach ($billingLocations as $location)<option value="{{ $location->id }}">{{ $location->name }}{{ $location->city ? ' · '.$location->city : '' }}{{ $location->state ? ', '.$location->state : '' }}{{ $location->gstin ? ' · GSTIN '.$location->gstin : '' }}</option>@endforeach
                        </select>
                        <span class="text-xs" style="color: var(--text-muted);">Billing address is copied from this Tan90 location, not the vendor. You can make PO-specific corrections below.</span>
                        @error('billingLocationId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
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
                <span class="font-medium mt-2" style="color: var(--text-primary);">Notes</span>
                <textarea wire:model="poNotes" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="e.g. Duration, GST, delivery notes"></textarea>
                <span class="text-xs" style="color: var(--text-muted);">Filled from <a href="{{ route('terms-templates.index') }}" class="underline" style="color: var(--brand);">Terms &amp; Notes</a> when you pick the vendor and Purchase Nature - edit freely.</span>
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
                                    <select wire:model.live="items.{{ $index }}.product" class="rounded-lg border px-3 py-2 text-sm" style="border-color: {{ $errors->has("items.$index.product") ? 'var(--status-critical)' : 'var(--border)' }}; background: var(--surface-1);" aria-invalid="{{ $errors->has("items.$index.product") ? 'true' : 'false' }}">
                                        <option value="">{{ $purchaseNature ? 'Search and pick a SKU' : 'Choose Nature of PO first' }}</option>
                                        @if ($item['product'] && ! $skuOptions->contains('sku', $item['product']))<option value="{{ $item['product'] }}">{{ $item['product'] }}</option>@endif
                                        @foreach ($skuOptions as $sku)<option value="{{ $sku->sku }}">{{ $sku->display_label }}</option>@endforeach
                                    </select>
                                    <span class="text-xs" style="color: var(--text-muted);">{{ $skuOptions->count() }} {{ $purchaseNature ? 'SKUs for this PO type' : 'SKUs' }}</span>
                                    @error("items.$index.product") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                </label>
                                <label class="flex flex-col gap-1.5 text-sm">
                                    <span class="font-medium" style="color: var(--text-primary);">HSN / SAC <span class="font-normal" style="color: var(--text-muted);">(fills from the item)</span></span>
                                    <input wire:model="items.{{ $index }}.hsnSac" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Optional — e.g. 39269099" />
                                    <span class="text-xs" style="color: var(--status-warning);">GST rate fills from item tax master; HSN tax rules are the fallback.</span>
                                    @error("items.$index.hsnSac") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                </label>
                                <label class="flex flex-col gap-1.5 text-sm">
                                    <span class="font-medium" style="color: var(--text-primary);">GST (%)</span>
                                    <input wire:model="items.{{ $index }}.gstRate" type="number" min="0" max="100" step="0.001" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Auto from item" />
                                    @error("items.$index.gstRate") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
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
                <button type="button" wire:click="openPoPreview" class="rounded-lg px-3.5 py-2 text-sm font-semibold text-white" style="background: var(--brand);">Preview PO</button>
            </div>
        </div>
    @endif

    @if ($poPreviewOpen)
        <div class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-3 sm:p-5 overflow-y-auto" style="background: rgba(15, 23, 42, 0.58);" role="dialog" aria-modal="true" aria-labelledby="po-preview-title" wire:key="po-create-preview">
            <section class="w-full max-w-5xl rounded-2xl border p-4 sm:p-6 space-y-4 max-h-[94vh] overflow-y-auto" style="background: var(--surface-1); border-color: var(--border);">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div><p class="text-xs font-semibold uppercase tracking-wide" style="color:var(--brand);">Document preview · before saving</p><h2 id="po-preview-title" class="text-xl font-bold mt-1" style="color:var(--text-primary);">PO document preview</h2><p class="text-sm mt-1" style="color:var(--text-secondary);">Check the supplier, addresses, items and estimated total. Nothing is saved until you choose an action below.</p></div>
                    <div class="flex flex-wrap items-center gap-2"><span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--surface-2);color:var(--text-secondary);">{{ $poNumber ?: 'PO number assigned on review' }}</span><button type="button" wire:click="{{ $poPdfPreviewOpen ? 'showPoPdfSummary' : 'showPoPdfView' }}" wire:loading.attr="disabled" wire:target="showPoPdfView" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--brand);">{{ $poPdfPreviewOpen ? 'Show summary' : 'Show PDF View' }}</button></div>
                </header>
                @if ($errors->any())
                    <div role="alert" aria-live="assertive" class="rounded-lg border px-4 py-3 text-sm" style="border-color:var(--status-critical);background:var(--status-critical-bg);color:var(--status-critical);">
                        <p class="font-semibold">{{ $errors->count() }} {{ \Illuminate\Support\Str::plural('field', $errors->count()) }} need attention before this PO can be saved.</p>
                        <ul class="mt-2 list-disc pl-5 text-xs space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif
                @if ($poPdfPreviewOpen && $poPdfPreviewToken !== '')
                    <iframe id="po-pdf-preview-frame" title="Purchase order PDF preview" data-testid="po-pdf-preview-frame" src="{{ route('admin.po.draft-preview', $poPdfPreviewToken) }}" class="w-full rounded-xl border bg-white" style="height:min(68vh, 820px);border-color:var(--border);" loading="eager"></iframe>
                @else
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <article class="rounded-xl border p-3" style="border-color:var(--border);"><h3 class="text-xs font-semibold uppercase" style="color:var(--text-muted);">Supplier / shipping address</h3><p class="font-semibold mt-2">{{ $vendorName ?: 'Select a vendor' }}</p><p class="text-sm mt-1" style="color:var(--text-secondary);">{{ collect([$previewVendor?->address_building, $previewVendor?->address_street, $previewVendor?->address_city, $previewVendor?->address_state, $previewVendor?->address_zip])->filter()->implode(', ') ?: 'Supplier address not available' }}</p><p class="text-xs mt-1" style="color:var(--text-muted);">GSTIN: {{ $previewVendor?->gst_number ?: 'Not provided' }}</p></article>
                    <article class="rounded-xl border p-3" style="border-color:var(--border);"><h3 class="text-xs font-semibold uppercase" style="color:var(--text-muted);">Billing address</h3><p class="font-semibold mt-2">{{ $billingBuilding ?: 'Not entered' }}</p><p class="text-sm mt-1" style="color:var(--text-secondary);">{{ collect([$billingStreet, $billingCity, $billingState, $billingZip, $billingCountry])->filter()->implode(', ') }}</p></article>
                    <article class="rounded-xl border p-3" style="border-color:var(--border);"><h3 class="text-xs font-semibold uppercase" style="color:var(--text-muted);">Delivery address</h3><p class="font-semibold mt-2">{{ $warehouseLocation ?: 'Choose a delivery warehouse / location' }}</p><p class="text-sm mt-1" style="color:var(--text-secondary);">{{ collect([$shippingBuilding, $shippingStreet, $shippingCity, $shippingState, $shippingZip, $shippingCountry])->filter()->implode(', ') ?: 'Delivery address not entered' }}</p></article>
                </div>
                <div class="rounded-xl border overflow-x-auto" style="border-color:var(--border);"><table class="w-full min-w-[700px] text-sm"><thead><tr class="text-left" style="background:var(--surface-2);color:var(--text-secondary);"><th class="p-3">#</th><th class="p-3">Product / SKU</th><th class="p-3 text-right">Quantity</th><th class="p-3">UOM</th><th class="p-3 text-right">Rate</th><th class="p-3 text-right">GST</th><th class="p-3 text-right">Line total</th></tr></thead><tbody>
                    @foreach ($previewLines as $index => $line)
                        @if (filled($line['product'] ?? null) || filled($line['quantity'] ?? null))<tr wire:key="po-preview-line-{{ $index }}" style="border-top:1px solid var(--border);"><td class="p-3">{{ $index + 1 }}</td><td class="p-3 font-medium">{{ $line['product'] ?: 'Product required' }}@if(filled($line['hsnSac'] ?? null))<span class="block text-xs" style="color:var(--text-muted);">HSN {{ $line['hsnSac'] }}</span>@endif</td><td class="p-3 text-right">{{ $line['quantity'] ?: '—' }}</td><td class="p-3">{{ $line['uom'] ?: '—' }}</td><td class="p-3 text-right">₹{{ number_format((float) ($line['listPrice'] ?? 0), 2) }}</td><td class="p-3 text-right">{{ number_format((float) ($line['preview_rate'] ?? 0), 2) }}% · ₹{{ number_format((float) ($line['preview_tax'] ?? 0), 2) }}</td><td class="p-3 text-right font-semibold">₹{{ number_format((float) ($line['preview_total'] ?? 0), 2) }}</td></tr>@endif
                    @endforeach
                    </tbody><tfoot><tr style="border-top:1px solid var(--border);"><td colspan="6" class="p-3 text-right font-semibold">Estimated total</td><td class="p-3 text-right text-lg font-bold">₹{{ number_format((float) $previewLines->sum('preview_total'), 2) }}</td></tr></tfoot></table></div>
                @endif
                <div class="flex flex-wrap justify-end gap-2 border-t pt-4" style="border-color:var(--border);">
                    <button type="button" wire:click="closePoPreview" class="rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--text-primary);">Back to edit</button>
                    <button type="button" wire:click="addPo" wire:loading.attr="disabled" wire:target="addPo" class="rounded-lg border px-4 py-2 text-sm font-semibold disabled:opacity-50" style="border-color:var(--border);color:var(--text-primary);">Save as Draft</button>
                    <button type="button" wire:click="saveAndSubmit" wire:loading.attr="disabled" wire:target="saveAndSubmit" class="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background:var(--brand);">Submit for release review</button>
                </div>
            </section>
        </div>
    @endif

    @if ($releasePreviewPo)
        <div class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-3 sm:p-5 overflow-y-auto" style="background: rgba(15, 23, 42, 0.58);" role="dialog" aria-modal="true" aria-labelledby="po-release-preview-title" wire:key="po-release-preview-{{ $releasePreviewPo->id }}">
            <section class="w-full max-w-4xl rounded-2xl border p-4 sm:p-6 space-y-4 max-h-[94vh] overflow-y-auto" style="background:var(--surface-1);border-color:var(--border);">
                <header class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-wide" style="color:var(--brand);">Final review</p><h2 id="po-release-preview-title" class="text-xl font-bold mt-1">Review PO before release</h2><p class="text-sm mt-1" style="color:var(--text-secondary);">The vendor can view and acknowledge this PO after release. Confirm the PDF and all three address sections first.</p></div><div class="flex flex-wrap gap-2"><a href="{{ route('admin.po.preview', $releasePreviewPo->po_number) }}" target="_blank" rel="noopener" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--brand);">Open PDF preview</a><a href="{{ route('admin.po.pdf', $releasePreviewPo->po_number) }}" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--text-primary);">Download PDF</a></div></header>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    @php $releaseVendor = \App\Models\VendorMaster::where('vendor_name', $releasePreviewPo->vendor_name)->first(); @endphp
                    <article class="rounded-xl border p-3" style="border-color:var(--border);"><h3 class="text-xs font-semibold uppercase" style="color:var(--text-muted);">Supplier / shipping address</h3><p class="font-semibold mt-2">{{ $releasePreviewPo->vendor_name }}</p><p class="text-sm mt-1" style="color:var(--text-secondary);">{{ collect([$releaseVendor?->address_building, $releaseVendor?->address_street, $releaseVendor?->address_city, $releaseVendor?->address_state, $releaseVendor?->address_zip])->filter()->implode(', ') ?: 'Supplier address not available' }}</p><p class="text-xs mt-1" style="color:var(--text-muted);">GSTIN: {{ $releaseVendor?->gst_number ?: 'Not provided' }}</p></article>
                    <article class="rounded-xl border p-3" style="border-color:var(--border);"><h3 class="text-xs font-semibold uppercase" style="color:var(--text-muted);">Billing address</h3><p class="font-semibold mt-2">{{ $releasePreviewPo->billing_building ?: 'Missing' }}</p><p class="text-sm mt-1" style="color:var(--text-secondary);">{{ collect([$releasePreviewPo->billing_street, $releasePreviewPo->billing_city, $releasePreviewPo->billing_state, $releasePreviewPo->billing_zip, $releasePreviewPo->billing_country])->filter()->implode(', ') }}</p></article>
                    <article class="rounded-xl border p-3" style="border-color:var(--border);"><h3 class="text-xs font-semibold uppercase" style="color:var(--text-muted);">Delivery address</h3><p class="font-semibold mt-2">{{ $releasePreviewPo->warehouse_location ?: 'Not specified' }}</p><p class="text-sm mt-1" style="color:var(--text-secondary);">{{ collect([$releasePreviewPo->shipping_building, $releasePreviewPo->shipping_street, $releasePreviewPo->shipping_city, $releasePreviewPo->shipping_state, $releasePreviewPo->shipping_zip, $releasePreviewPo->shipping_country])->filter()->implode(', ') ?: 'Delivery address not provided' }}</p></article>
                </div>
                <div class="rounded-xl border overflow-x-auto" style="border-color:var(--border);"><table class="w-full min-w-[560px] text-sm"><thead><tr class="text-left" style="background:var(--surface-2);"><th class="p-3">PO item</th><th class="p-3 text-right">Quantity</th><th class="p-3">UOM</th><th class="p-3 text-right">Rate</th><th class="p-3 text-right">Line total</th></tr></thead><tbody>@foreach($releasePreviewPo->lines as $line)<tr wire:key="po-release-line-{{ $line->id }}" style="border-top:1px solid var(--border);"><td class="p-3">{{ $line->documentProduct() }}</td><td class="p-3 text-right">{{ \App\Support\IndianNumberFormatter::quantity($line->quantity) }}</td><td class="p-3">{{ $line->uom }}</td><td class="p-3 text-right">₹{{ number_format((float)$line->list_price,2) }}</td><td class="p-3 text-right font-semibold">₹{{ number_format($line->amount(),2) }}</td></tr>@endforeach</tbody><tfoot><tr style="border-top:1px solid var(--border);"><td colspan="4" class="p-3 text-right font-semibold">PO total</td><td class="p-3 text-right text-lg font-bold">₹{{ number_format($releasePreviewPo->grandTotal(),2) }}</td></tr></tfoot></table></div>
                <div class="flex flex-wrap justify-end gap-2 border-t pt-4" style="border-color:var(--border);"><button type="button" wire:click="closeReleasePreview" class="rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color:var(--border);">Back</button><button type="button" wire:click="confirmReleasePo" wire:loading.attr="disabled" wire:target="confirmReleasePo" class="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background:var(--brand);">Confirm &amp; release to vendor</button></div>
            </section>
        </div>
    @endif

    @if ($virtualPo)
        <div class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 overflow-y-auto" style="background: rgba(15, 23, 42, 0.55);" role="dialog" aria-modal="true" aria-labelledby="virtual-title" wire:key="virtual-modal-{{ $virtualPo->id }}">
            <div class="w-full max-w-2xl rounded-2xl border p-5 space-y-4" style="background: var(--surface-1); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="virtual-title" class="text-lg font-bold" style="color: var(--text-primary);">Virtual GRN: {{ $virtualPo->po_number }}</h2>
                        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $virtualPo->vendor_name }} ships these goods straight to the customer. The ERP books them in (GRN, vendor bill for Finance) and out to the customer at the same time, marked "Virtual GRN".</p>
                    </div>
                    <button type="button" wire:click="$set('virtualPoId', null)" class="text-sm" style="color: var(--text-muted);" aria-label="Close">✕</button>
                </div>
                @error('virtual') <div role="alert" class="rounded-lg border px-3 py-2 text-xs" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">{{ $message }}</div> @enderror
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <label class="flex flex-col gap-1 text-sm sm:col-span-3"><span class="font-medium">Customer the vendor shipped to *</span>
                        <input wire:model="virtualCustomer" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        @error('virtualCustomer') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1 text-sm sm:col-span-2"><span class="font-medium">Vendor invoice number *</span>
                        <input wire:model="virtualInvoice" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        @error('virtualInvoice') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Invoice date *</span>
                        <input wire:model="virtualInvoiceDate" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        @error('virtualInvoiceDate') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                </div>
                <div>
                    <div class="text-sm font-medium mb-1">Quantity shipped direct</div>
                    <div class="rounded-lg border divide-y" style="border-color: var(--border);">
                        @foreach ($virtualPo->lines as $line)
                            <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm" wire:key="virtual-line-{{ $line->id }}">
                                <span class="min-w-0 truncate">{{ $line->documentProduct() }} <span class="text-xs" style="color: var(--text-muted);">· ordered {{ \App\Support\IndianNumberFormatter::quantity($line->quantity) }} {{ $line->uom }}</span></span>
                                <span class="flex flex-col items-end">
                                    <input wire:model="virtualLines.{{ $line->id }}" type="number" min="0" step="1" class="w-28 rounded-lg border px-2 py-1 text-sm text-right" style="border-color: var(--border);">
                                    @error('virtualLines.'.$line->id) <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                </span>
                            </div>
                        @endforeach
                    </div>
                    @error('virtualLines') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </div>
                <div class="flex flex-wrap gap-2 pt-1">
                    <button type="button" wire:click="saveVirtual" wire:loading.attr="disabled" wire:target="saveVirtual" class="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--brand);">Create Virtual GRN</button>
                    <button type="button" wire:click="$set('virtualPoId', null)" class="rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                </div>
            </div>
        </div>
    @endif
    @if ($bypassPo)
        {{-- Bypass popup: the vendor's acceptance + dispatch documents, done by the Store Manager. --}}
        <div class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 overflow-y-auto" style="background: rgba(15, 23, 42, 0.55);" role="dialog" aria-modal="true" aria-labelledby="bypass-title" wire:key="bypass-modal-{{ $bypassPo->id }}">
            <div class="w-full max-w-2xl rounded-2xl border p-5 space-y-4" style="background: var(--surface-1); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="bypass-title" class="text-lg font-bold" style="color: var(--text-primary);">Accept for vendor: {{ $bypassPo->po_number }}</h2>
                        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $bypassPo->vendor_name }} can't use the portal. Upload the documents the vendor would send; the PO then goes to the Guard as usual.</p>
                    </div>
                    <button type="button" wire:click="$set('bypassingPoId', null)" class="text-sm" style="color: var(--text-muted);" aria-label="Close">✕</button>
                </div>

                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Reason (optional)</span>
                    <input wire:model="bypassReason" placeholder="e.g. Vendor confirmed on phone, sent invoice on WhatsApp" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @error('bypassReason') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </label>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Vendor invoice number (optional)</span>
                        <input wire:model="bypassInvoiceNumber" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        @error('bypassInvoiceNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Invoice date (optional)</span>
                        <input wire:model="bypassInvoiceDate" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        @error('bypassInvoiceDate') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Vehicle number</span>
                        <input wire:model="bypassVehicle" placeholder="MH 04 AB 1234" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    </label>
                    <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Expected arrival</span>
                        <input wire:model="bypassArrival" type="datetime-local" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        @error('bypassArrival') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div>
                    <div class="text-sm font-medium mb-1">Quantity being dispatched</div>
                    <div class="rounded-lg border divide-y" style="border-color: var(--border);">
                        @foreach ($bypassPo->lines as $line)
                            <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm" wire:key="bypass-line-{{ $line->id }}">
                                <span class="min-w-0 truncate">{{ $line->documentProduct() }} <span class="text-xs" style="color: var(--text-muted);">· ordered {{ \App\Support\IndianNumberFormatter::quantity($line->quantity) }} {{ $line->uom }}</span></span>
                                <span class="flex flex-col items-end">
                                    <input wire:model="bypassLines.{{ $line->id }}" type="number" min="0" step="0.001" class="w-28 rounded-lg border px-2 py-1 text-sm text-right" style="border-color: var(--border);">
                                    @error('bypassLines.'.$line->id) <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                </span>
                            </div>
                        @endforeach
                    </div>
                    @error('bypassLines') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </div>

                <div>
                    <div class="text-sm font-medium mb-1">Documents <span class="font-normal text-xs" style="color: var(--text-muted);">(optional; PDF or photo, up to 5 MB each)</span></div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        @foreach (['bypassInvoiceFile' => 'Invoice', 'bypassEwayFile' => 'E-way Bill', 'bypassLrFile' => 'LR / POD'] as $field => $label)
                            <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">{{ $label }}</span>
                                <input wire:model="{{ $field }}" type="file" accept=".pdf,.jpg,.jpeg,.png" class="text-xs">
                                <span wire:loading wire:target="{{ $field }}" style="color: var(--text-muted);">Uploading…</span>
                                @error($field) <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 pt-1">
                    <button type="button" wire:click="bypassVendorAcceptance" wire:loading.attr="disabled" wire:target="bypassVendorAcceptance,bypassInvoiceFile,bypassEwayFile,bypassLrFile" class="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--brand);">Accept & submit documents for vendor</button>
                    <button type="button" wire:click="$set('bypassingPoId', null)" class="rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                </div>
            </div>
        </div>
    @endif
    @unless ($adding)
    <div class="mb-3 flex flex-wrap gap-2" role="group" aria-label="Purchase order status filters">
        <button type="button" wire:click="$set('poStatusFilter', '')" class="min-h-10 rounded-lg border px-3 py-2 text-xs font-semibold" style="border-color:{{ $poStatusFilter === '' ? 'var(--brand)' : 'var(--border)' }};color:{{ $poStatusFilter === '' ? 'var(--brand)' : 'var(--text-secondary)' }};">All ({{ array_sum($poStatusCounts->all()) }})</button>
        @foreach ($statuses as $statusOption)
            <button type="button" wire:click="$set('poStatusFilter', '{{ $poStatusFilter === $statusOption ? '' : $statusOption }}')" class="min-h-10 rounded-lg border px-3 py-2 text-xs font-semibold" style="border-color:{{ $poStatusFilter === $statusOption ? 'var(--brand)' : 'var(--border)' }};color:{{ $poStatusFilter === $statusOption ? 'var(--brand)' : 'var(--text-secondary)' }};">{{ $statusOption }} {{ $poStatusCounts[$statusOption] ?? 0 }}</button>
        @endforeach
    </div>
    <div class="mb-2 grid grid-cols-1 gap-2 rounded-2xl border p-3 sm:grid-cols-2 xl:grid-cols-6" style="border-color:var(--border);background:var(--surface-3);">
        <input wire:model.live.debounce.400ms="poLookup" type="search" placeholder="Search PO number or vendor" aria-label="Search purchase orders" class="min-h-11 rounded-lg border px-3 py-2 text-sm xl:col-span-2" style="border-color:var(--border);background:var(--surface-1);" />
        <label class="sr-only" for="po-vendor-filter">Filter by vendor</label>
        <select id="po-vendor-filter" wire:model.live="poVendorFilter" aria-label="Filter by vendor" class="min-h-11 rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border);background:var(--surface-1);"><option value="">All vendors</option>@foreach ($vendorOptions as $vendorOption)<option value="{{ $vendorOption }}">{{ $vendorOption }}</option>@endforeach</select>
        <label class="sr-only" for="po-location-filter">Filter by location</label>
        <select id="po-location-filter" wire:model.live="poLocationFilter" aria-label="Filter by location" class="min-h-11 rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border);background:var(--surface-1);"><option value="">All locations</option>@foreach ($poLocationOptions as $locationOption)<option value="{{ $locationOption->id }}">{{ $locationOption->name }}</option>@endforeach</select>
        <label class="flex flex-col gap-1 text-xs" style="color:var(--text-muted);">PO date from<input type="date" wire:model.live="poDateFrom" class="min-h-11 rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border);background:var(--surface-1);"></label>
        <label class="flex flex-col gap-1 text-xs" style="color:var(--text-muted);">PO date to<input type="date" wire:model.live="poDateTo" class="min-h-11 rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border);background:var(--surface-1);"></label>
    </div>
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <span class="mr-auto text-xs" style="color:var(--text-muted);">Showing {{ $orders->firstItem() ?? 0 }}–{{ $orders->lastItem() ?? 0 }} of {{ $ordersTotal }} filtered POs · 200 per page.</span>
        <button type="button" wire:click="selectVisiblePos" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--text-primary);">Select this page</button>
        <button type="button" wire:click="selectAllMatchingPos" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--text-primary);">Select all matching filters</button>
        @if ($selectedPoIds || $allMatchingPos)<span class="text-xs">{{ $allMatchingPos ? max(0, $ordersTotal - count($excludedPoIds)) : count($selectedPoIds) }} selected</span><button type="button" wire:click="clearPoSelection" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--text-secondary);">Clear selection</button>@endif
        <button type="button" wire:click="exportFilteredPos" wire:loading.attr="disabled" wire:target="exportFilteredPos" class="min-h-11 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background:var(--brand);">
            <span wire:loading.remove wire:target="exportFilteredPos">{{ $selectedPoIds || $allMatchingPos ? 'Export Selected' : 'Export Filtered' }}</span>
            <span wire:loading wire:target="exportFilteredPos" role="status">Preparing CSV…</span>
        </button>
    </div>
    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-2 py-2.5"><input type="checkbox" aria-label="Select this page" x-on:change="$event.target.checked ? $wire.call('selectVisiblePos') : $wire.call('clearPoSelection')"></th>
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
                            <td class="px-2 py-2.5">@if($allMatchingPos)<input type="checkbox" @checked(!in_array((string) $po->id, $excludedPoIds, true)) wire:click="toggleExcludedPo({{ $po->id }})" aria-label="Select PO {{ $po->po_number }}">@else<input type="checkbox" wire:model.live="selectedPoIds" value="{{ $po->id }}" aria-label="Select PO {{ $po->po_number }}">@endif</td>
                            <td class="px-2 py-2.5">
                                <a href="{{ route('admin.po.show', $po->po_number) }}" wire:navigate aria-label="Open {{ $po->po_number }} details" style="color: var(--text-muted);">›</a>
                            </td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">
                                <a href="{{ route('admin.po.show', $po->po_number) }}" wire:navigate class="min-h-11 inline-flex items-center rounded-lg px-2 -mx-2 text-left transition-colors hover:bg-black/5 focus:outline-none focus-visible:ring-2" style="color: var(--brand); --tw-ring-color: var(--brand);" title="Open PO details">
                                    <span class="underline underline-offset-2">{{ $po->po_number }}</span>
                                </a>
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
                                @if ($po->isReleased() && ! ($po->acknowledgement?->accepted ?? false))
                                    <button type="button" wire:click="openBypass({{ $po->id }})" class="mt-1 text-xs font-semibold underline" style="color: var(--brand);" title="Manually accept for the vendor and optionally record dispatch details">Manual bypass: accept for vendor</button>
                                @endif
                                @if ($po->isReleased())
                                    <div class="mt-1 font-semibold" style="color: var(--status-good);">Released — visible in vendor portal</div>
                                @else
                                    <div class="mt-1 font-semibold" style="color: var(--status-warning);">Draft — vendor cannot see this yet</div>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">₹{{ \App\Support\IndianNumberFormatter::format($po->grandTotal()) }}
                                <x-amount-breakdown :b="app(\App\Services\AmountBreakdown::class)->forPo($po)" compact class="font-normal mt-0.5" />
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.po.preview', $po->po_number) }}" target="_blank" rel="noopener" class="font-medium" style="color: var(--brand);">Preview PDF</a>
                                    <a href="{{ route('admin.po.pdf', $po->po_number) }}" class="font-medium" style="color: var(--brand);">Download PDF</a>
                                    @if ($po->purchase_nature === 'Trading' && $po->isReleased())
                                        <button type="button" wire:click="openVirtual({{ $po->id }})" class="font-medium" style="color: var(--brand);" title="Vendor ships straight to the customer: GRN in and dispatch out in one step">Virtual GRN</button>
                                    @endif
                                    @unless ($po->isReleased())
                                        <button type="button" wire:click="openAddressEdit({{ $po->id }})" class="font-medium" style="color: var(--brand);">Edit address</button>
                                        <button type="button" wire:click="openReleasePreview({{ $po->id }})" wire:loading.attr="disabled" wire:target="openReleasePreview({{ $po->id }})" class="font-medium disabled:opacity-50" style="color: var(--brand);">Review &amp; release</button>
                                    @endunless
                                    <button wire:click="deletePo({{ $po->id }})" wire:confirm="Remove PO {{ $po->po_number }}?" style="color: var(--status-critical);">Remove</button>
                                </div>
                            </td>
                        </tr>
                        @if ($addressPoId === $po->id)
                            <tr style="background: var(--surface-2);" wire:key="po-address-{{ $po->id }}">
                                <td colspan="7" class="px-4 py-3">
                                    <div class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Billing address for {{ $po->po_number }}</div>
                                    @error('addressEdit') <div role="alert" class="text-xs mb-2" style="color: var(--status-warning);">{{ $message }}</div> @enderror
                                    <div class="grid grid-cols-1 sm:grid-cols-5 gap-2">
                                        @foreach (['building' => 'Building / name', 'street' => 'Street', 'city' => 'City', 'state' => 'State', 'zip' => 'PIN code'] as $key => $label)
                                            <label class="flex flex-col gap-1 text-xs" style="color: var(--text-secondary);">{{ $label }}
                                                <input wire:model="addressForm.{{ $key }}" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);">
                                                @error('addressForm.'.$key) <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="flex flex-wrap gap-2 mt-3">
                                        <button type="button" wire:click="saveAddress(true)" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--brand);">Save &amp; release to vendor</button>
                                        <button type="button" wire:click="saveAddress(false)" wire:loading.attr="disabled" class="rounded-lg border px-3 py-1.5 text-sm font-semibold" style="border-color: var(--border); color: var(--text-primary);">Save only</button>
                                        <button type="button" wire:click="$set('addressPoId', null)" class="text-sm" style="color: var(--text-muted);">Cancel</button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No purchase orders match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
    @endunless
</div>
