<?php

namespace App\Providers;

use App\Console\Commands\SyncZohoPurchaseOrders;
use App\Console\Commands\PushZohoPurchaseOrders;
use App\Console\Commands\PushZohoMasterData;
use App\Console\Commands\PushZohoOperationalData;
use App\Console\Commands\PushZohoInventoryData;
use App\Console\Commands\SyncZohoInventoryMasterData;
use App\Console\Commands\SyncZohoInventoryPurchaseOrders;
use App\Console\Commands\SyncZohoMasterData;
use App\Console\Commands\PushZohoWorkflowData;
use App\Models\DebitNote;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\Tan90\MasterData\Customer as MasterDataCustomer;
use App\Models\Tan90\MasterData\Item as MasterDataItem;
use App\Models\Tan90\MasterData\Vendor as MasterDataVendor;
use App\Models\GrnRecord;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\QcResult;
use App\Models\Rfq;
use App\Models\SkuMaster;
use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\CostSheet;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\VendorSubmission;
use App\Models\VendorMaster;
use App\Observers\BomRecipeCostingObserver;
use App\Observers\DebitNoteObserver;
use App\Observers\FinanceRecordObserver;
use App\Observers\Tan90\MasterDataCustomerObserver;
use App\Observers\Tan90\MasterDataItemObserver;
use App\Observers\Tan90\MasterDataVendorObserver;
use App\Observers\GateEntryObserver;
use App\Observers\GrnRecordObserver;
use App\Observers\PurchaseOrderLineObserver;
use App\Observers\PurchaseOrderObserver;
use App\Observers\QcResultObserver;
use App\Observers\RfqObserver;
use App\Observers\SkuMasterObserver;
use App\Observers\VendorSubmissionObserver;
use App\Observers\VendorMasterObserver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->commands([
            SyncZohoPurchaseOrders::class,
            PushZohoPurchaseOrders::class,
            SyncZohoMasterData::class,
            PushZohoMasterData::class,
            PushZohoWorkflowData::class,
            PushZohoOperationalData::class,
            PushZohoInventoryData::class,
            SyncZohoInventoryMasterData::class,
            SyncZohoInventoryPurchaseOrders::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sign-in/sign-out are deliberately not written to the audit trail.
        PurchaseOrder::observe(PurchaseOrderObserver::class);
        PurchaseOrderLine::observe(PurchaseOrderLineObserver::class);
        VendorMaster::observe(VendorMasterObserver::class);
        SkuMaster::observe(SkuMasterObserver::class);
        Rfq::observe(RfqObserver::class);
        VendorSubmission::observe(VendorSubmissionObserver::class);
        FinanceRecord::observe(FinanceRecordObserver::class);
        DebitNote::observe(DebitNoteObserver::class);
        MasterDataVendor::observe(MasterDataVendorObserver::class);
        MasterDataItem::observe(MasterDataItemObserver::class);
        MasterDataCustomer::observe(MasterDataCustomerObserver::class);
        GateEntry::observe(GateEntryObserver::class);
        QcResult::observe(QcResultObserver::class);
        GrnRecord::observe(GrnRecordObserver::class);
        Bom::observe(BomRecipeCostingObserver::class);
        Recipe::observe(BomRecipeCostingObserver::class);
        CostSheet::observe(BomRecipeCostingObserver::class);

        $this->app->booted(function () {
            // Staging shares the live Zoho organization. Keep every scheduled
            // Zoho job off there until the client explicitly enables it.
            if (! app()->environment('staging') || config('services.zoho.staging_sync_enabled')) {
                $minutes = max(5, (int) config('services.zoho.sync_minutes', 30));

                $this->app->make(Schedule::class)
                    ->command(SyncZohoPurchaseOrders::class)
                    ->cron("*/{$minutes} * * * *")
                    ->withoutOverlapping();

                $this->app->make(Schedule::class)
                    ->command(PushZohoPurchaseOrders::class)
                    ->cron("*/{$minutes} * * * *")
                    ->withoutOverlapping();

                $this->app->make(Schedule::class)
                    ->command(SyncZohoMasterData::class)
                    ->cron("*/{$minutes} * * * *")
                    ->withoutOverlapping();

                $this->app->make(Schedule::class)
                    ->command(PushZohoMasterData::class)
                    ->cron("*/{$minutes} * * * *")
                    ->withoutOverlapping();

                // These legacy CRM jobs scan and push every historical row. The staging
                // database is a production clone, so running them there would replay
                // copied records into the shared live Zoho organization on every tick.
                if (! app()->environment('staging')) {
                    $this->app->make(Schedule::class)
                        ->command(PushZohoWorkflowData::class)
                        ->cron("*/{$minutes} * * * *")
                        ->withoutOverlapping();

                    $this->app->make(Schedule::class)
                        ->command(PushZohoOperationalData::class)
                        ->cron("*/{$minutes} * * * *")
                        ->withoutOverlapping();
                }

                $this->app->make(Schedule::class)
                    ->command(PushZohoInventoryData::class)
                    ->cron("*/{$minutes} * * * *")
                    ->withoutOverlapping();

                $this->app->make(Schedule::class)
                    ->command(SyncZohoInventoryMasterData::class)
                    ->cron("*/{$minutes} * * * *")
                    ->withoutOverlapping();

                $this->app->make(Schedule::class)
                    ->command(SyncZohoInventoryPurchaseOrders::class)
                    ->cron("*/{$minutes} * * * *")
                    ->withoutOverlapping();

                // Zoho is the source of truth for Items: hourly GET-only pull, off
                // until ZOHO_ITEMS_PULL_ENABLED=true.
                if (config('services.zoho_items_pull.schedule_enabled')) {
                    $this->app->make(Schedule::class)
                        ->command('zoho:pull-items')
                        ->hourly()
                        ->withoutOverlapping();
                    $this->app->make(Schedule::class)
                        ->command('zoho:pull-fields')
                        ->hourly()
                        ->withoutOverlapping();
                    // Incremental: only composites changed in Zoho are re-fetched.
                    $this->app->make(Schedule::class)
                        ->command('zoho:pull-recipes')
                        ->hourlyAt(20)
                        ->withoutOverlapping();
                    // Old Zoho POs not in the ERP yet, a daily batch at night until all are in.
                    $this->app->make(Schedule::class)
                        ->command('zoho:pull-all-pos --max=1500')
                        ->dailyAt('01:30')
                        ->withoutOverlapping();
                    // Vendor / customer addresses (Zoho's contact list has none), a batch a night.
                    if (config('services.zoho_items_pull.address_pull_enabled')) {
                        $this->app->make(Schedule::class)
                            ->command('zoho:pull-contact-addresses --max=1500')
                            ->dailyAt('03:30')
                            ->withoutOverlapping();
                    }
                }
            }
        });
    }
}
