<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Critical safety net: this app's Zoho credentials live in the real
     * .env, and several Eloquent observers (FinanceRecordObserver,
     * PurchaseOrderObserver, VendorMasterObserver, SkuMasterObserver, and
     * the Tan90 Master Data Vendor/Item/Customer observers) fire real
     * outbound Zoho HTTP calls on every save with NO test-environment
     * awareness of their own. Confirmed live: running this suite was
     * creating real Bills, Vendor Credits, and Contacts in the actual
     * production Zoho account — test fixture names ("Acme Vendor", "Always
     * Rejected Co", "Resilient Vendor", etc.) were found sitting in the
     * real Zoho org. Every test class inherits this setUp(), so this blanks
     * every Zoho credential/flag before each test runs; a test that
     * deliberately exercises Zoho sync (search this suite for Http::fake())
     * sets its own config in its own setUp(), which runs after this one and
     * overrides it, so real sync-behavior tests are unaffected.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.zoho.client_id' => null,
            'services.zoho.client_secret' => null,
            'services.zoho.refresh_token' => null,
            'services.zoho.inventory.organization_id' => null,
            'services.zoho.inventory.refresh_token' => null,
            'services.zoho.inventory.sync_enabled' => false,
        ]);
    }
}
