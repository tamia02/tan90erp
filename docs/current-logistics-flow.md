# Current logistics flow

Based on the 3 November 2026 staging code snapshot supplied in Google Drive. That application code and its pending schema migrations were deployed to the Bookmytimes ERP while retaining its existing database and the Zoho sales-document import.

## Sales fulfilment: Flow module

1. **Order team:** create a customer order with site, FG warehouse, customer and product lines. The newer order form requires an approved FG mapping for that site/warehouse. Validate and release the order.
2. **Stock and production:** check eligible released FG lots and reserve stock by earliest expiry first (FEFO), within the selected site/warehouse and shelf-life requirements. A shortage triggers production planning. After production/QC, receive FG into the mapped warehouse and complete bin putaway with timing and manpower details. Stock receipt/putaway can retry allocation for pending orders.
3. **Picking:** select the fully allocated order lines, build/publish a picking wave and confirm actual picked quantities. Managed orders require all lines and mapped stock before picking proceeds.
4. **Packing:** pack picked goods into handling units and seal them. The managed dispatch path checks that all required quantities are picked and packed.
5. **Dispatch/transport:** create a shipment with vehicle, transporter, driver, dock, seal and temperature logger information. Load sealed handling units and release the shipment. Stock movements are recorded at release; orders move to in transit.
6. **Finance handoff:** shipment release creates a finance review task for site-linked orders. The current handoff records review acknowledgement; it is not an automated payment or accounting settlement.
7. **Delivery/closure:** record delivery/POD. An unresolved temperature excursion blocks delivery recording. A POD reference is required before closing the delivery/order. Returns and claims have a separate request and inspection path.

## Physical outward gate: Inventory / Outward Shipment

This is a separate path in the current code:

**Create outward shipment and package details → attach required invoice/challan, IRN and E-Way Bill information → Guard creates outward gate pass → Store Manager assigns dock → Store Executive completes loading/document handover → Guard confirms vehicle exit.**

For this path, the truck exit is the dispatch and is when stock is deducted. Missing required documents or insufficient stock block exit. Do not also deduct the same shipment through the Flow dispatch path; the company process must decide how the two paths should be connected.

## Zoho import and the remaining company-process mapping

Zoho sales orders, packages and invoices are imported into an organization-scoped records table and can be searched under **Flow → Zoho Sales / Packages / Invoices**. Importing these records does **not** create operational Flow customer orders, picking tasks, dispatches, stock movements, or invoice PDFs automatically.

The company's forthcoming logistics PDF is needed to map its actual ownership, approvals and handoffs, including how Zoho orders and packages should start ERP fulfilment and how the Flow dispatch and physical gate paths should relate. That mapping has not been implemented merely by importing documents or copying the staging system.

## Upgrade verification

- Database and application code backups were taken before the upgrade.
- All 33 pending staging migrations passed against an isolated restored database and then the target database.
- Zoho document counts were retained; the three imported-record tabs were verified in the live browser.
- Logistics and production index-page tests and the three Zoho-import tests passed.
- Three existing legacy end-to-end tests did not pass against the newer workflow. Their old fixtures omit newer workflow requirements; the full company-specific operating flow still requires acceptance testing after the PDF is mapped.
