<?php

namespace App\Console\Commands;

use App\Models\Tan90\MasterData\Customer;
use App\Models\VendorMaster;
use App\Services\Zoho\ContactAddressService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

// Fills vendor / customer addresses from Zoho (read-only), a batch per night.
// Contacts used on POs come first. Each contact Zoho was asked about is marked,
// so ones with no address in Zoho are not asked again.
class PullZohoContactAddresses extends Command
{
    protected $signature = 'zoho:pull-contact-addresses {--max=1500 : Most contacts to fetch in this run} {--dry-run : Only count what is missing}';

    protected $description = 'Bring vendor and customer addresses from Zoho (read-only), in nightly batches.';

    public function handle(ContactAddressService $addresses): int
    {
        if (! $addresses->configured()) {
            $this->error('Zoho is not configured.');

            return self::FAILURE;
        }
        $lock = Cache::lock('zoho:pull-contact-addresses', 4 * 3600);
        if (! $lock->get()) {
            $this->warn('Another run is still going.');

            return self::SUCCESS;
        }

        try {
            $addresses->setPause(app()->runningUnitTests() ? 0 : 1100); // about 55 calls a minute

            $onPos = DB::table('purchase_orders')->distinct()->pluck('vendor_name')->flip();
            $vendors = VendorMaster::whereNull('zoho_address_checked_at')
                ->where(fn ($q) => $q->whereNull('address_city')->orWhere('address_city', '')->orWhereNull('address_zip')->orWhere('address_zip', ''))
                ->get()->sortBy(fn ($v) => $onPos->has($v->vendor_name) ? 0 : 1)->values();
            $customers = Customer::whereNull('zoho_address_checked_at')
                ->where(fn ($q) => $q->whereNull('city')->orWhere('city', '')->orWhereNull('address_zip')->orWhere('address_zip', ''))
                ->get();
            $this->info("Missing an address: {$vendors->count()} vendors, {$customers->count()} customers.");
            if ($this->option('dry-run') || ($vendors->isEmpty() && $customers->isEmpty())) {
                return self::SUCCESS;
            }

            $budget = (int) $this->option('max');
            $filled = ['vendor' => 0, 'customer' => 0];
            $asked = 0;
            foreach (['vendor' => $vendors, 'customer' => $customers] as $type => $rows) {
                if ($rows->isEmpty() || $asked >= $budget) {
                    continue;
                }
                $addresses->loadContactIds($type);
                foreach ($rows as $row) {
                    if ($asked >= $budget) {
                        break;
                    }
                    $asked++;
                    try {
                        $done = $type === 'vendor' ? $addresses->fillVendor($row) : $addresses->fillCustomer($row);
                        $filled[$type] += $done ? 1 : 0;
                    } catch (Throwable $e) {
                        $this->warn(($row->vendor_name ?? $row->name).': '.$e->getMessage());
                    }
                    if ($row->zoho_address_checked_at === null) {
                        $this->warn('Zoho stopped answering (likely the daily limit) - the next run continues.');
                        break 2;
                    }
                }
            }
            $this->info("Asked Zoho about {$asked} contacts; filled {$filled['vendor']} vendor and {$filled['customer']} customer addresses.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
