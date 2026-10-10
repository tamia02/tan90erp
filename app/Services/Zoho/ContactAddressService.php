<?php

namespace App\Services\Zoho;

use App\Models\Tan90\MasterData\Customer;
use App\Models\VendorMaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

// Vendor / customer billing address from Zoho, READ-ONLY. Zoho's contact list
// has no address, so each contact's detail is fetched once (nightly batch, or
// on the spot when a PO picks a contact that has none). Rows are written with
// the query builder: no model events, so nothing is pushed back to Zoho.
class ContactAddressService
{
    public function __construct(private ZohoReadClient $zoho) {}

    public function configured(): bool
    {
        return (bool) config('services.zoho_items_pull.address_pull_enabled') && $this->zoho->configured();
    }

    /** Fill a vendor's empty address from Zoho. True when something was filled. */
    public function fillVendor(VendorMaster $vendor): bool
    {
        if (filled($vendor->address_city) && filled($vendor->address_zip)) {
            return false;
        }
        $address = $this->fetch($vendor->vendor_name, 'vendor');
        if ($address === null) {
            return false; // Zoho unreachable: try again later
        }
        $update = ['zoho_address_checked_at' => now()];
        foreach (['building' => 'address_building', 'street' => 'address_street', 'city' => 'address_city', 'state' => 'address_state', 'zip' => 'address_zip', 'country' => 'address_country'] as $from => $to) {
            if (blank($vendor->{$to}) && filled($address[$from] ?? null)) {
                $update[$to] = $address[$from];
            }
        }
        DB::table('vendor_masters')->where('id', $vendor->id)->update($update);
        // Master Data > Vendors shows city / state too.
        if (filled($update['address_city'] ?? null) || filled($update['address_state'] ?? null)) {
            DB::table('tan90_vendors')->where('name', $vendor->vendor_name)
                ->update(array_filter(['city' => $update['address_city'] ?? null, 'state' => $update['address_state'] ?? null]));
        }
        $vendor->forceFill($update)->syncOriginal();

        return count($update) > 1;
    }

    public function fillCustomer(Customer $customer): bool
    {
        if (filled($customer->city) && filled($customer->address_zip)) {
            return false;
        }
        $address = $this->fetch($customer->name, 'customer');
        if ($address === null) {
            return false;
        }
        $update = ['zoho_address_checked_at' => now()];
        foreach (['building' => 'address_building', 'street' => 'address_street', 'city' => 'city', 'state' => 'state', 'zip' => 'address_zip'] as $from => $to) {
            if (blank($customer->{$to}) && filled($address[$from] ?? null)) {
                $update[$to] = $address[$from];
            }
        }
        DB::table('tan90_customers')->where('id', $customer->id)->update($update);
        $customer->forceFill($update)->syncOriginal();

        return count($update) > 1;
    }

    /**
     * Billing address (or shipping when billing is empty) of the Zoho contact
     * with this name. [] = Zoho has no address; null = Zoho could not be asked.
     *
     * @return array{building?: string, street?: string, city?: string, state?: string, zip?: string, country?: string}|null
     */
    public function fetch(string $name, string $type): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        try {
            $key = mb_strtolower(trim($name));
            // Nightly run has the full name -> id map; a single click searches by name (1 call).
            $map = Cache::get($this->mapKey($type));
            $id = $map !== null ? ($map[$key] ?? null) : collect($this->zoho->get('/contacts', ['contact_type' => $type, 'contact_name' => $name])['contacts'] ?? [])
                ->first(fn ($c) => mb_strtolower(trim((string) ($c['contact_name'] ?? ''))) === $key)['contact_id'] ?? null;
            if (! $id) {
                return [];
            }
            $contact = $this->zoho->get("/contacts/{$id}")['contact'] ?? [];
        } catch (Throwable) {
            return null;
        }
        $hasAddress = fn ($a) => is_array($a) && array_filter([$a['address'] ?? null, $a['city'] ?? null, $a['zip'] ?? null]);
        $a = $hasAddress($contact['billing_address'] ?? null) ? $contact['billing_address'] : ($contact['shipping_address'] ?? []);
        if (! $hasAddress($a)) {
            // No address in Zoho, but the list's "place of contact" still gives the state.
            return array_filter(['state' => $contact['place_of_contact_formatted'] ?? null]);
        }

        return array_filter([
            'building' => trim((string) ($a['attention'] ?? '')) ?: null,
            'street' => trim(($a['address'] ?? '').' '.($a['street2'] ?? '')) ?: null,
            'city' => $a['city'] ?? null,
            'state' => $a['state'] ?? null,
            'zip' => $a['zip'] ?? null,
            'country' => $a['country'] ?? null,
        ]);
    }

    /** Zoho contact name (lower-case) -> contact_id, from one walk of the contact list. @return array<string, string> */
    public function loadContactIds(string $type): array
    {
        $map = collect($this->zoho->all('/contacts', 'contacts', ['contact_type' => $type]))
            ->mapWithKeys(fn ($c) => [mb_strtolower(trim((string) ($c['contact_name'] ?? ''))) => (string) $c['contact_id']])
            ->all();
        Cache::put($this->mapKey($type), $map, now()->addDay());

        return $map;
    }

    private function mapKey(string $type): string
    {
        return "zoho:contact-ids:{$type}:".md5((string) config('services.zoho_items_pull.organization_id'));
    }

    public function setPause(int $ms): void
    {
        $this->zoho->pauseMs = $ms;
    }
}
