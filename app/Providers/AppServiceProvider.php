<?php

namespace App\Providers;

use App\Console\Commands\SyncZohoPurchaseOrders;
use App\Console\Commands\PushZohoPurchaseOrders;
use App\Console\Commands\PushZohoMasterData;
use App\Console\Commands\PushZohoOperationalData;
use App\Console\Commands\PushZohoInventoryData;
use App\Console\Commands\SyncZohoMasterData;
use App\Console\Commands\PushZohoWorkflowData;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
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
use App\Observers\FinanceRecordObserver;
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
        GateEntry::observe(GateEntryObserver::class);
        QcResult::observe(QcResultObserver::class);
        GrnRecord::observe(GrnRecordObserver::class);
        Bom::observe(BomRecipeCostingObserver::class);
        Recipe::observe(BomRecipeCostingObserver::class);
        CostSheet::observe(BomRecipeCostingObserver::class);

        $this->app->booted(function () {
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

            $this->app->make(Schedule::class)
                ->command(PushZohoWorkflowData::class)
                ->cron("*/{$minutes} * * * *")
                ->withoutOverlapping();

            $this->app->make(Schedule::class)
                ->command(PushZohoOperationalData::class)
                ->cron("*/{$minutes} * * * *")
                ->withoutOverlapping();

            $this->app->make(Schedule::class)
                ->command(PushZohoInventoryData::class)
                ->cron("*/{$minutes} * * * *")
                ->withoutOverlapping();
        });
    }
}
